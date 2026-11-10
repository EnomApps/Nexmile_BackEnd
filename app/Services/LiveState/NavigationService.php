<?php

namespace App\Services\LiveState;

use App\Contracts\RouteProvider;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Turn-by-turn directions for the rider app (EP9).
 *
 * The rider navigates inside our app rather than handing them to Google Maps,
 * which means we buy the turns. That is the whole difficulty: every request
 * here is a billed Directions call, and the delivery fee it comes out of is
 * about ₹29.
 *
 * A rider parked just off the route, or an app retrying a failure, can ask
 * every few seconds for a quarter of an hour. Left alone that is more spent on
 * directions than the delivery earns. So this holds two lines:
 *
 *   - a cooldown, shorter than the app's own, that serves the route we already
 *     have instead of buying it again
 *   - a ceiling per order, past which the last route is reused and nothing
 *     more is bought
 *
 * Neither ever fails the rider. A rider mid-delivery with no directions is a
 * worse outcome than a route ten seconds out of date, so every limit here
 * degrades to something stale rather than to nothing.
 */
class NavigationService
{
    public const PICKUP = 'pickup';

    public const DROPOFF = 'dropoff';

    public function __construct(protected RouteProvider $routes) {}

    /**
     * Is there a maps key behind this at all?
     *
     * Separate from a failed route so the app can tell "not switched on yet",
     * which is permanent until somebody configures it, from "no road to this
     * address", which is about this one stop.
     */
    public function configured(): bool
    {
        return $this->routes->configured();
    }

    /**
     * Directions from where the rider is to one of the order's two stops.
     *
     * Null means no route — no road, a refused key, or an address with no
     * coordinates behind it. The app draws a straight dotted line and the
     * rider still gets there, so this is an ordinary answer rather than a
     * failure worth shouting about.
     *
     * @return array{polyline: string, duration_seconds: int, distance_metres: int, steps: array<int, mixed>}|null
     */
    public function to(Order $order, string $target, float $latitude, float $longitude, ?string $language = null): ?array
    {
        $stop = $this->stop($order, $target);

        if ($stop === null) {
            return null;
        }

        $cooldown = 'nav:wait:'.$order->id.':'.$target;
        $held = 'nav:last:'.$order->id.':'.$target;

        $last = Cache::get($held);

        /*
         * Inside the cooldown. The app asks at most once every 15 seconds and
         * this window is 10, so a rider who has genuinely gone off route is
         * never held up by it — what it catches is a retry loop, a second
         * device, or two screens open on the same order.
         */
        if (Cache::has($cooldown)) {
            return $last;
        }

        if ($this->spent($order) >= (int) config('tracking.navigation.max_per_order')) {
            /*
             * Worth knowing about: a rider who hits this is either lost or
             * holding an order they are not riding, and both are worth a look
             * at the end of the day.
             */
            Log::info('Navigation ceiling reached for an order.', [
                'order_id' => $order->id,
                'target' => $target,
            ]);

            return $last;
        }

        $route = $this->routes->navigation(
            $latitude,
            $longitude,
            $stop['latitude'],
            $stop['longitude'],
            $language,
        );

        /*
         * The cooldown is set whether or not the call worked. A failure that
         * can be retried immediately is a failure that gets retried every
         * poll, and a maps outage then becomes one billed request per rider
         * per second.
         */
        Cache::put($cooldown, true, (int) config('tracking.navigation.cooldown_seconds'));

        $this->charge($order);

        if ($route === null) {
            // Keep serving the last good one rather than dropping the rider
            // onto a straight line mid-junction because one call failed.
            return $last;
        }

        Cache::put($held, $route, (int) config('tracking.navigation.keep_seconds'));

        return $route;
    }

    /**
     * Where the rider is actually going.
     *
     * An address with no coordinates cannot be routed to — the rider app sends
     * those to the phone's own maps app instead, which can search text.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    protected function stop(Order $order, string $target): ?array
    {
        [$latitude, $longitude] = $target === self::PICKUP
            ? [$order->merchant?->latitude, $order->merchant?->longitude]
            : [$order->delivery_latitude, $order->delivery_longitude];

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return ['latitude' => (float) $latitude, 'longitude' => (float) $longitude];
    }

    /**
     * How many billed calls this order has already cost.
     *
     * Counted per order rather than per rider or per target: the thing being
     * protected is the margin on one delivery, and a rider riding two legs of
     * the same order is spending the same fee twice.
     */
    protected function spent(Order $order): int
    {
        return (int) Cache::get($this->meter($order), 0);
    }

    protected function charge(Order $order): void
    {
        $key = $this->meter($order);

        /*
         * add() then increment(), because increment() on a missing key is a
         * no-op on some stores rather than a write of 1 — the counter would
         * sit at zero forever and the ceiling would never be reached.
         */
        Cache::add($key, 0, (int) config('tracking.navigation.meter_seconds'));

        Cache::increment($key);
    }

    protected function meter(Order $order): string
    {
        return 'nav:spent:'.$order->id;
    }
}
