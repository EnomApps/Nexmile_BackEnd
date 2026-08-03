<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\LiveState\OrderStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The merchant's half of the order lifecycle (EP5, EP8).
 *
 * Every status change goes through here. A controller setting
 * `$order->status` directly would skip the history row, the timestamp and the
 * Redis mirror, and those three are what the customer's tracking screen, the
 * dispatch queue and the payout report each read.
 */
class OrderStatusService
{
    /**
     * What a merchant may do, and from where.
     *
     * Deliberately not the full lifecycle: RiderAssigned, PickedUp and
     * Delivered belong to dispatch and the rider app (EP8, EP10). A merchant
     * cannot mark an order delivered — they never handle it after pickup.
     *
     * @var array<string, list<string>>
     */
    protected const MERCHANT_TRANSITIONS = [
        'accept' => [OrderStatus::Placed->value],
        'reject' => [OrderStatus::Placed->value],
        'preparing' => [OrderStatus::Accepted->value],
        'ready' => [OrderStatus::Accepted->value, OrderStatus::Preparing->value],
    ];

    public function __construct(protected OrderStateService $liveState) {}

    /**
     * @throws ValidationException
     */
    public function accept(Order $order, User $actor, ?int $prepMinutes = null): Order
    {
        $this->guard($order, 'accept');

        return $this->transition($order, OrderStatus::Accepted, $actor, attributes: [
            'accepted_at' => now(),
            // Falls back to the merchant's configured average so the customer
            // always sees an estimate, even when the merchant just taps Accept.
            'estimated_prep_minutes' => $prepMinutes ?? $order->merchant->avg_prep_time_minutes ?? 20,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function reject(Order $order, User $actor, string $reason): Order
    {
        $this->guard($order, 'reject');

        return $this->transition($order, OrderStatus::Rejected, $actor, note: $reason, attributes: [
            'cancelled_at' => now(),
            'cancelled_by' => 'merchant',
            'cancellation_reason' => $reason,
            /*
             * No cancellation fee. The customer did nothing wrong, and a
             * rejected order is refunded in full — charging them for the
             * merchant's decision would be indefensible.
             */
            'cancellation_fee' => 0,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function startPreparing(Order $order, User $actor): Order
    {
        $this->guard($order, 'preparing');

        return $this->transition($order, OrderStatus::Preparing, $actor);
    }

    /**
     * @throws ValidationException
     */
    public function markReady(Order $order, User $actor): Order
    {
        $this->guard($order, 'ready');

        return $this->transition($order, OrderStatus::ReadyForPickup, $actor, attributes: [
            'ready_at' => now(),
        ]);
    }

    /**
     * @throws ValidationException
     */
    protected function guard(Order $order, string $action): void
    {
        $allowed = self::MERCHANT_TRANSITIONS[$action];

        if (! in_array($order->status->value, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => $this->refusal($order->status, $action),
            ]);
        }
    }

    /**
     * A merchant tapping Accept on an order the customer cancelled two seconds
     * earlier needs to know which of those happened, not just that it failed.
     */
    protected function refusal(OrderStatus $current, string $action): string
    {
        return match (true) {
            $current === OrderStatus::Cancelled => 'This order was cancelled.',
            $current === OrderStatus::Rejected => 'You already rejected this order.',
            $current === OrderStatus::PendingPayment => 'This order is not paid for yet.',
            $action === 'accept' && $current !== OrderStatus::Placed => 'This order has already been accepted.',
            default => 'This order is already '.str_replace('_', ' ', $current->value).'.',
        };
    }

    /**
     * The write itself: status, timestamps, history and live state, in that
     * order and in one transaction.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function transition(
        Order $order,
        OrderStatus $to,
        User $actor,
        ?string $note = null,
        array $attributes = [],
    ): Order {
        $from = $order->status;

        DB::transaction(function () use ($order, $to, $from, $actor, $note, $attributes) {
            $order->update([...$attributes, 'status' => $to]);

            $order->statusHistory()->create([
                'from_status' => $from,
                'to_status' => $to,
                'changed_by_user_id' => $actor->id,
                'note' => $note,
                'created_at' => now(),
            ]);
        });

        /*
         * Outside the transaction, and swallowed: Redis is a cache of MySQL,
         * so it must not roll back a status the merchant has already been
         * told about. Losing Redis costs the tracking screen its latency, not
         * the order its state — the hash rebuilds from the orders table.
         */
        rescue(fn () => $this->liveState->setStatus($order->id, $to), report: true);

        return $order->refresh();
    }
}
