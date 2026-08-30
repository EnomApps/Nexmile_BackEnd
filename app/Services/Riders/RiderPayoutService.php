<?php

namespace App\Services\Riders;

use App\Models\Order;
use App\Services\Discovery\NearbyMerchantService;
use Illuminate\Support\Carbon;

/**
 * What a rider earns for one delivery.
 *
 * First mile, waiting, last mile — the three things a rider actually does, in
 * the order they do them. Within a 1 km radius each is small and predictable,
 * which is what makes it possible to tell a rider what a job pays before they
 * accept it rather than after.
 *
 * Every figure is snapshotted onto the order at delivery. A payout recomputed
 * later from current rates would quietly restate what someone was already
 * paid, and a rider who cannot check last week's number against last week's
 * rates has no reason to trust this week's.
 */
class RiderPayoutService
{
    public function __construct(protected NearbyMerchantService $geo) {}

    /**
     * Work out and store what this delivery earned.
     *
     * Called once, on delivery. Returns the order with the payout on it.
     */
    public function settle(Order $order, ?Carbon $at = null): Order
    {
        if (! config('rider_pay.enabled') || $order->rider_id === null) {
            return $order;
        }

        // Already settled. Delivering is a one-way transition, but a retried
        // job or a support tool must not pay twice.
        if ($order->rider_payout !== null) {
            return $order;
        }

        $breakdown = $this->calculate($order, $at ?? now());

        $order->forceFill([
            'rider_payout' => $breakdown['total'],
            'rider_payout_breakdown' => $breakdown,
        ])->save();

        return $order;
    }

    /**
     * The arithmetic, with every part named.
     *
     * Public and side-effect free so the rider app can quote a job before it
     * is accepted using the same code that pays for it. A quote and a payment
     * that disagree is worse than no quote.
     *
     * @return array<string, mixed>
     */
    public function calculate(Order $order, ?Carbon $at = null): array
    {
        $at ??= now();

        $firstMile = $this->firstMile($order);
        $waiting = $this->waiting($order);
        $lastMile = $this->lastMile($order);

        $earned = $this->round($firstMile['amount'] + $waiting['amount'] + $lastMile['amount']);

        /*
         * The floor is applied before incentives, not after. A short hop plus
         * a peak bonus should beat a short hop, or the incentive buys nothing
         * on exactly the orders nobody wants to take.
         */
        $minimum = (float) config('rider_pay.minimum_payout');
        $topUp = max(0.0, $this->round($minimum - $earned));
        $base = $this->round($earned + $topUp);

        $incentives = $this->incentives($order, $at);

        return [
            'first_mile' => $firstMile,
            'waiting' => $waiting,
            'last_mile' => $lastMile,
            'minimum_top_up' => $topUp,
            'base' => $base,
            'incentives' => $incentives['lines'],
            'incentive_total' => $incentives['total'],
            'total' => $this->round($base + $incentives['total']),
            // Stamped so a rider reading an old order sees the rates it was
            // paid under, not today's.
            'rates_version' => config('rider_pay.minimum_payout').'/'
                .config('rider_pay.first_mile.base').'/'
                .config('rider_pay.last_mile.base'),
        ];
    }

    /**
     * Rider's position when they accepted, to the restaurant.
     *
     * @return array<string, mixed>
     */
    private function firstMile(Order $order): array
    {
        $metres = $order->first_mile_metres;

        if ($metres === null && $order->accepted_latitude !== null && $order->merchant?->latitude !== null) {
            $metres = (int) round($this->geo->distance(
                (float) $order->accepted_latitude,
                (float) $order->accepted_longitude,
                (float) $order->merchant->latitude,
                (float) $order->merchant->longitude,
            ));
        }

        return $this->leg($metres, config('rider_pay.first_mile'));
    }

    /**
     * Restaurant to customer.
     *
     * @return array<string, mixed>
     */
    private function lastMile(Order $order): array
    {
        $metres = $order->last_mile_metres ?? $order->distance_metres;

        return $this->leg($metres, config('rider_pay.last_mile'));
    }

    /**
     * A leg: flat within the base distance, per kilometre beyond it.
     *
     * An unknown distance is paid at the base rather than at zero. The rider
     * did the journey either way, and a missing GPS fix is our problem.
     *
     * @param  array<string, mixed>  $rate
     * @return array<string, mixed>
     */
    private function leg(?int $metres, array $rate): array
    {
        $base = (float) $rate['base'];

        if ($metres === null) {
            return ['metres' => null, 'amount' => $base, 'note' => 'distance unknown, base rate applied'];
        }

        $beyond = max(0, $metres - (int) $rate['base_metres']);
        $extra = $this->round($beyond / 1000 * (float) $rate['per_km_beyond']);

        return [
            'metres' => $metres,
            'amount' => $this->round($base + $extra),
            'beyond_base_metres' => $beyond,
        ];
    }

    /**
     * Time between arriving at the restaurant and collecting.
     *
     * @return array<string, mixed>
     */
    private function waiting(Order $order): array
    {
        $config = config('rider_pay.waiting');

        /*
         * Measured from the geofenced arrival, never from acceptance. The gap
         * between accepting and collecting is travel plus waiting, and paying
         * for it would pay a rider for their own journey twice — once in the
         * first mile and again by the minute.
         */
        if ($order->arrived_at === null || $order->picked_up_at === null) {
            return ['minutes' => null, 'paid_minutes' => 0, 'amount' => 0.00, 'note' => 'arrival not recorded'];
        }

        $minutes = (int) floor(abs($order->arrived_at->diffInMinutes($order->picked_up_at)));
        $minutes = max(0, min($minutes, (int) $config['max_minutes']));

        $paid = max(0, $minutes - (int) $config['free_minutes']);

        return [
            'minutes' => $minutes,
            'paid_minutes' => $paid,
            'amount' => $this->round($paid * (float) $config['per_minute']),
        ];
    }

    /**
     * Peak and weather surcharges, capped against what the order earns.
     *
     * A flat peak-plus-weather bonus can cost more than a small order makes,
     * and it lands exactly on the wet Friday evening when the most orders are
     * running. The cap is a share of the order's own margin, so a busy night
     * cannot quietly run at a loss.
     *
     * @return array{lines: array<string, float>, total: float}
     */
    private function incentives(Order $order, Carbon $at): array
    {
        $config = config('rider_pay.incentives');
        $lines = [];

        if ($this->isPeak($at)) {
            $lines['peak'] = (float) $config['peak'];
        }

        /*
         * A switch ops flip when it is raining, not a per-order field —
         * nobody is going to tag five hundred orders by hand on a wet
         * evening, and the weather is the same for every rider in the zone.
         */
        if (config('rider_pay.incentives.bad_weather_active')) {
            $lines['bad_weather'] = (float) $config['bad_weather'];
        }

        $total = $this->round(array_sum($lines));

        /*
         * What the order itself brings in: commission plus whatever delivery
         * fee the customer actually paid. On a free-delivery order that is
         * commission alone, which is exactly where an uncapped bonus hurts.
         */
        $margin = (float) $order->commission_amount + (float) $order->delivery_fee;
        $cap = $this->round($margin * (float) $config['max_share_of_order_margin']);

        if ($total > $cap) {
            $lines['capped_from'] = $total;
            $total = max(0.0, $cap);
        }

        return ['lines' => $lines, 'total' => $total];
    }

    private function isPeak(Carbon $at): bool
    {
        foreach (config('rider_pay.incentives.peak_hours') as [$from, $to]) {
            if ($at->format('H:i') >= $from && $at->format('H:i') < $to) {
                return true;
            }
        }

        return false;
    }

    private function round(float $amount): float
    {
        return round($amount, 2);
    }
}
