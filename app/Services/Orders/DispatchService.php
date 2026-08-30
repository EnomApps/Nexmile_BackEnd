<?php

namespace App\Services\Orders;

use App\Enums\FulfilmentType;
use App\Enums\OrderStatus;
use App\Enums\RiderStatus;
use App\Models\Order;
use App\Models\Rider;
use App\Services\Discovery\NearbyMerchantService;
use App\Services\LiveState\DispatchQueueService;
use App\Services\LiveState\RiderLocationService;
use App\Services\Riders\RiderPayoutService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Getting a ready order onto a rider (EP8).
 *
 * Runs as an **open board**: ready orders appear on a list that nearby on-duty
 * riders poll, and the first to accept wins. See config/dispatch.php for why
 * that rather than push-with-timeout.
 */
class DispatchService
{
    public function __construct(
        protected OrderStatusService $status,
        protected RiderLocationService $locations,
        protected DispatchQueueService $queue,
        protected NearbyMerchantService $geo,
        protected RiderPayoutService $payouts,
    ) {}

    /**
     * Orders a given rider could take right now, nearest restaurant first.
     *
     * @return Collection<int, Order>
     */
    public function board(Rider $rider): Collection
    {
        if (! $this->canWork($rider)) {
            return collect();
        }

        [$latitude, $longitude] = $this->positionOf($rider);

        if ($latitude === null || $longitude === null) {
            // No position means no way to rank by distance, and a rider who
            // has never pinged is probably not actually out on the road.
            return collect();
        }

        $radius = (int) config('dispatch.board_radius_metres');

        return Order::query()
            ->with(['merchant', 'items'])
            ->where('status', OrderStatus::ReadyForPickup->value)
            ->where('fulfilment_type', FulfilmentType::Delivery->value)
            ->whereNull('rider_id')
            /*
             * A rider must never be offered their own order. Otherwise: order
             * food, deliver it to yourself, mark it delivered, keep the
             * delivery fee. Repeatable at will, and it looks like a rider who
             * is simply very fast.
             */
            ->where('user_id', '!=', $rider->user_id)
            ->when($rider->zone_id, fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('zone_id')->orWhere('zone_id', $rider->zone_id)))
            ->get()
            ->map(function (Order $order) use ($latitude, $longitude) {
                $order->pickup_distance_metres = $order->merchant?->latitude === null ? null
                    : round($this->geo->distance(
                        $latitude, $longitude,
                        (float) $order->merchant->latitude, (float) $order->merchant->longitude,
                    ));

                return $order;
            })
            ->filter(fn (Order $o) => $o->pickup_distance_metres !== null && $o->pickup_distance_metres <= $radius)
            ->sortBy('pickup_distance_metres')
            ->take((int) config('dispatch.board_limit'))
            ->values();
    }

    /**
     * Claim an order.
     *
     * The whole race is settled by one conditional UPDATE: `rider_id` is only
     * written where it is still null and the status is still ready. Two riders
     * tapping Accept at the same instant produce one winner and one 422 — no
     * lock, no transaction isolation to reason about, and correct even across
     * separate PHP processes.
     *
     * @throws ValidationException
     */
    public function accept(Rider $rider, Order $order): Order
    {
        $this->guardRider($rider);
        $this->guardOrder($rider, $order);

        $claimed = Order::query()
            ->whereKey($order->id)
            ->whereNull('rider_id')
            ->where('status', OrderStatus::ReadyForPickup->value)
            ->update(['rider_id' => $rider->id]);

        if ($claimed !== 1) {
            throw ValidationException::withMessages([
                'order' => 'Another rider took this order.',
            ]);
        }

        $order->refresh();

        /*
         * Where the rider was when they took it. The first mile is measured
         * from here and there is no way back to it afterwards: a rider's
         * position exists only in a Redis set with a TTL, and the next ping
         * overwrites it.
         *
         * The last mile is stored at the same moment because both legs should
         * come from one consistent view of the world.
         */
        [$latitude, $longitude] = $this->positionOf($rider);

        $order->forceFill(array_filter([
            'accepted_latitude' => $latitude,
            'accepted_longitude' => $longitude,
            'first_mile_metres' => $latitude !== null && $order->merchant?->latitude !== null
                ? (int) round($this->geo->distance(
                    $latitude, $longitude,
                    (float) $order->merchant->latitude, (float) $order->merchant->longitude,
                ))
                : null,
            'last_mile_metres' => $order->distance_metres,
        ], fn ($value) => $value !== null))->save();

        $this->status->markAssigned($order, $rider->user);

        $rider->update(['duty_status' => RiderStatus::OnOrder]);
        rescue(fn () => $this->locations->setDutyStatus($rider->id, RiderStatus::OnOrder->value), report: true);
        rescue(fn () => $this->queue->remove((int) $order->zone_id, $order->id), report: true);

        return $order->refresh();
    }

    /**
     * @throws ValidationException
     */
    public function pickUp(Rider $rider, Order $order, string $code): Order
    {
        $this->guardAssignment($rider, $order);

        return $this->status->markPickedUp($order, $rider->user, $code);
    }

    /**
     * Hand an order back to the board.
     *
     * Only before collection — `release` is not a transition from picked_up,
     * so food already in a bag cannot be abandoned this way.
     *
     * @throws ValidationException
     */
    public function release(Rider $rider, Order $order, ?string $reason = null): Order
    {
        $this->guardAssignment($rider, $order);

        $released = $this->status->releaseByRider($order, $rider->user, $reason);

        $rider->update(['duty_status' => RiderStatus::Available]);
        rescue(fn () => $this->locations->setDutyStatus($rider->id, RiderStatus::Available->value), report: true);

        return $released;
    }

    /**
     * @throws ValidationException
     */
    public function deliver(Rider $rider, Order $order): Order
    {
        $this->guardAssignment($rider, $order);

        $delivered = $this->status->markDelivered($order, $rider->user);

        // Settle the payout now, against the facts as they stand. Recomputing
        // it later from current rates would restate what someone was paid.
        $this->payouts->settle($delivered);

        $rider->update([
            'duty_status' => RiderStatus::Available,
            'completed_deliveries' => $rider->completed_deliveries + 1,
        ]);

        rescue(fn () => $this->locations->setDutyStatus($rider->id, RiderStatus::Available->value), report: true);

        return $delivered;
    }

    /**
     * A rider may hold only so many orders at once — one, by default. At 1 km
     * batching a second drop saves a few minutes and costs that customer their
     * food going cold in a bag.
     */
    public function activeOrderCount(Rider $rider): int
    {
        return $rider->orders()->active()->count();
    }

    public function canWork(Rider $rider): bool
    {
        return $rider->canAcceptOrders()
            && $rider->duty_status !== RiderStatus::Offline
            && $rider->duty_status !== RiderStatus::OnBreak;
    }

    /**
     * Live position first, falling back to the last one written to MySQL.
     * Redis being empty after a restart should not empty every rider's board.
     *
     * @return array{float|null, float|null}
     */
    protected function positionOf(Rider $rider): array
    {
        $state = rescue(fn () => $this->locations->state($rider->id), [], report: false);

        if (isset($state['latitude'], $state['longitude'])) {
            return [(float) $state['latitude'], (float) $state['longitude']];
        }

        return $rider->last_latitude === null
            ? [null, null]
            : [(float) $rider->last_latitude, (float) $rider->last_longitude];
    }

    /**
     * @throws ValidationException
     */
    protected function guardRider(Rider $rider): void
    {
        if (($reason = $rider->offlineReason()) !== null) {
            $this->fail('rider', $reason);
        }

        if ($rider->duty_status === RiderStatus::Offline || $rider->duty_status === RiderStatus::OnBreak) {
            $this->fail('rider', 'Go online before accepting orders.');
        }

        if ($this->activeOrderCount($rider) >= (int) config('dispatch.max_concurrent_orders_per_rider')) {
            $this->fail('rider', 'Finish your current delivery first.');
        }
    }

    /**
     * @throws ValidationException
     */
    protected function guardOrder(Rider $rider, Order $order): void
    {
        if ($order->fulfilment_type === FulfilmentType::Pickup) {
            $this->fail('order', 'This order is being collected by the customer.');
        }

        if ($order->user_id === $rider->user_id) {
            // The fraud guard, stated plainly rather than hidden behind a
            // generic refusal.
            $this->fail('order', 'You cannot deliver your own order.');
        }
    }

    /**
     * @throws ValidationException
     */
    protected function guardAssignment(Rider $rider, Order $order): void
    {
        if ($order->rider_id !== $rider->id) {
            $this->fail('order', 'This order is not assigned to you.');
        }
    }

    /**
     * @throws ValidationException
     */
    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
