<?php

namespace App\Services\LiveState;

use App\Contracts\RouteProvider;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Discovery\NearbyMerchantService;
use App\Support\RedisKeys;
use Illuminate\Support\Facades\Cache;

/**
 * What the customer's map shows while a delivery is happening.
 *
 * Three answers: where the rider is, how long until the food arrives, and the
 * line to draw between the two. They are built together because they are read
 * together — the customer app polls this every few seconds for the whole
 * delivery, and every one of those polls is a chance to spend money on a
 * Directions call that would tell us what we already knew.
 *
 * Which leg is drawn depends on whether the food has been collected:
 *
 *   before pickup — the restaurant to the customer. The rider is riding the
 *                   other way, towards the kitchen, and drawing their path
 *                   would show a line moving away from the person watching it.
 *   after pickup  — the rider to the customer. Now they are the same journey.
 *
 * Nothing here fails loudly. No key, no network, no route: the map still shows
 * a moving rider and an arrival time built from the straight line, which is
 * what the app did before any of this existed.
 */
class TrackingService
{
    public function __construct(
        protected RouteProvider $routes,
        protected NearbyMerchantService $geo,
        protected RiderLocationService $locations,
    ) {}

    /**
     * The live part of a tracking response.
     *
     * @return array{rider: array<string, mixed>|null, estimated_minutes: int|null, route: string|null}
     */
    public function for(Order $order): array
    {
        $position = $this->position($order);
        $leg = $this->leg($order, $position);

        $route = $leg === null ? null : $this->route($order, $leg);

        return [
            'rider' => $this->rider($order, $position),
            'estimated_minutes' => $this->minutes($order, $position, $route),
            'route' => $route['polyline'] ?? null,
        ];
    }

    /**
     * The rider block, flattened.
     *
     * `updated_at` is the fix time, not the time of this request. The app
     * decides whether to show "location updating" from it, and answering with
     * the server clock would mean a rider whose phone died ten minutes ago
     * still looked live.
     *
     * @param  array<string, mixed>|null  $position
     * @return array<string, mixed>|null
     */
    protected function rider(Order $order, ?array $position): ?array
    {
        if ($order->rider_id === null) {
            return null;
        }

        return array_merge([
            'name' => $order->rider?->full_name,
            'vehicle_number' => $order->rider?->vehicle_number,
            'phone' => $order->rider?->user?->phone,
            'latitude' => null,
            'longitude' => null,
            'heading' => null,
            'updated_at' => null,
        ], $position ?? []);
    }

    /**
     * Where the rider is, if the customer is entitled to see it.
     *
     * Only while this order is actually being carried. Once it is delivered,
     * where the rider goes next is their own business.
     *
     * @return array<string, mixed>|null
     */
    protected function position(Order $order): ?array
    {
        $carrying = in_array($order->status, [
            OrderStatus::RiderAssigned,
            OrderStatus::PickedUp,
        ], true);

        if (! $carrying || $order->rider_id === null) {
            return null;
        }

        $state = rescue(fn () => $this->locations->state($order->rider_id), [], report: false);

        if (! isset($state['latitude'], $state['longitude'])) {
            return null;
        }

        return [
            'latitude' => (float) $state['latitude'],
            'longitude' => (float) $state['longitude'],
            // Empty string is how an unknown heading is stored: a Redis hash
            // holds strings, and a literal 'null' would read back as the text.
            'heading' => ($state['heading'] ?? '') === '' ? null : (float) $state['heading'],
            /*
             * The fix time when the app sent one, falling back to when we
             * received it. An older rider build sends no recorded_at, and the
             * two meant the same thing then.
             *
             * Empty strings are checked for, not just missing keys: Redis
             * hashes hold strings, and ?? alone would hand the app a blank
             * timestamp rather than falling through to one it can use.
             */
            'updated_at' => $this->firstFilled($state, ['recorded_at', 'updated_at']),
        ];
    }

    /**
     * The first of these keys holding something.
     *
     * Redis hashes store strings, so a value that was never set and one
     * that was set to nothing are indistinguishable on read — and `??`
     * only sees the first of those.
     *
     * @param  array<string, mixed>  $state
     * @param  list<string>  $keys
     */
    protected function firstFilled(array $state, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $state[$key] ?? '';

            if ($value !== '' && $value !== null) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * The journey to draw: origin, destination, and whether it moves.
     *
     * Null when there is nothing to draw — no delivery coordinates, or an
     * order nobody is carrying yet.
     *
     * @param  array<string, mixed>|null  $position
     * @return array{from_lat: float, from_lng: float, to_lat: float, to_lng: float, moving: bool}|null
     */
    protected function leg(Order $order, ?array $position): ?array
    {
        if ($order->delivery_latitude === null || $order->delivery_longitude === null) {
            return null;
        }

        $destination = [
            'to_lat' => (float) $order->delivery_latitude,
            'to_lng' => (float) $order->delivery_longitude,
        ];

        // Carrying the food: the rider's own path is the delivery.
        if ($order->status === OrderStatus::PickedUp && $position !== null) {
            return $destination + [
                'from_lat' => $position['latitude'],
                'from_lng' => $position['longitude'],
                'moving' => true,
            ];
        }

        /*
         * Not collected yet, so draw the leg that is actually coming: kitchen
         * to door. It does not move while the rider fetches the food, which is
         * also why it caches well.
         */
        if ($order->merchant?->latitude === null || $order->merchant?->longitude === null) {
            return null;
        }

        return $destination + [
            'from_lat' => (float) $order->merchant->latitude,
            'from_lng' => (float) $order->merchant->longitude,
            'moving' => false,
        ];
    }

    /**
     * The drawn route, cached hard.
     *
     * A customer polling every 8 seconds across a 15 minute delivery is about
     * 110 requests. One Directions call each would cost more than the delivery
     * earns; one a minute costs almost nothing and draws a line nobody can
     * tell apart from the other.
     *
     * Cached against the origin it was computed from, so a rider who has
     * ridden far enough gets a fresh line before the TTL is up — and a leg
     * that does not move is simply reused.
     *
     * @param  array{from_lat: float, from_lng: float, to_lat: float, to_lng: float, moving: bool}  $leg
     * @return array{polyline: string, duration_seconds: int, distance_metres: int}|null
     */
    protected function route(Order $order, array $leg): ?array
    {
        if (! config('tracking.route.enabled')) {
            return null;
        }

        $key = RedisKeys::orderRoute($order->id);
        $cached = rescue(fn () => Cache::get($key), null, report: false);

        if (is_array($cached) && $this->stillGood($cached, $leg)) {
            // May legitimately hold null: a remembered failure, kept briefly so
            // one maps outage is not one Directions call per poll per customer.
            return $cached['route'] ?? null;
        }

        $route = $this->routes->between(
            $leg['from_lat'],
            $leg['from_lng'],
            $leg['to_lat'],
            $leg['to_lng'],
        );

        $ttl = $route === null
            ? (int) config('tracking.route.failure_ttl_seconds')
            : (int) config('tracking.route.ttl_seconds');

        rescue(fn () => Cache::put($key, [
            'route' => $route,
            'from_lat' => $leg['from_lat'],
            'from_lng' => $leg['from_lng'],
        ], now()->addSeconds($ttl)), report: false);

        return $route;
    }

    /**
     * Is a cached route still close enough to the rider to draw?
     *
     * @param  array<string, mixed>  $cached
     * @param  array{from_lat: float, from_lng: float, moving: bool}  $leg
     */
    protected function stillGood(array $cached, array $leg): bool
    {
        // The kitchen does not move, so the only thing that expires this leg
        // is the clock.
        if (! $leg['moving']) {
            return true;
        }

        if (! isset($cached['from_lat'], $cached['from_lng'])) {
            return false;
        }

        $moved = $this->geo->distance(
            (float) $cached['from_lat'],
            (float) $cached['from_lng'],
            $leg['from_lat'],
            $leg['from_lng'],
        );

        return $moved <= (float) config('tracking.route.recompute_after_metres');
    }

    /**
     * Minutes until the food arrives.
     *
     * Built from whatever is known, in descending order of trust: a routed
     * duration, then the straight line at a pessimistic town speed, then the
     * kitchen's own estimate. Null only when none of those exist — "Arriving
     * in —" is better than a confident wrong number.
     *
     * @param  array<string, mixed>|null  $position
     * @param  array{duration_seconds: int}|null  $route
     */
    protected function minutes(Order $order, ?array $position, ?array $route): ?int
    {
        if ($order->status->isTerminal()) {
            return null;
        }

        // Carrying it: the drawn leg is the whole remaining journey.
        if ($order->status === OrderStatus::PickedUp) {
            return $this->atLeastOne($this->legMinutes($order, $position, $route));
        }

        /*
         * Not collected yet, so three things still have to happen: the rider
         * reaches the kitchen, the food is handed over, and then the delivery
         * leg itself. Leaving the first two out is how a fifteen minute
         * promise quietly becomes eighteen.
         */
        $toKitchen = $this->ridingToKitchenMinutes($order, $position);
        $delivery = $this->legMinutes($order, null, $route);

        if ($toKitchen === null && $delivery === null) {
            return null;
        }

        return $this->atLeastOne(
            ($toKitchen ?? 0)
            + (int) config('tracking.handover_minutes')
            + ($delivery ?? 0)
        );
    }

    /**
     * How long the drawn leg takes.
     *
     * @param  array<string, mixed>|null  $position
     * @param  array{duration_seconds: int}|null  $route
     */
    protected function legMinutes(Order $order, ?array $position, ?array $route): ?int
    {
        if ($route !== null) {
            return (int) ceil($route['duration_seconds'] / 60);
        }

        if ($order->delivery_latitude === null || $order->delivery_longitude === null) {
            // No coordinates at all. The kitchen's own estimate is the last
            // thing left, and it beats silence.
            return $order->estimated_prep_minutes;
        }

        $fromLat = $position['latitude'] ?? $order->merchant?->latitude;
        $fromLng = $position['longitude'] ?? $order->merchant?->longitude;

        if ($fromLat === null || $fromLng === null) {
            return $order->estimated_prep_minutes;
        }

        return $this->minutesAcross($this->geo->distance(
            (float) $fromLat,
            (float) $fromLng,
            (float) $order->delivery_latitude,
            (float) $order->delivery_longitude,
        ));
    }

    /**
     * The rider's ride to the restaurant, estimated from the straight line.
     *
     * Never routed. It is a short hop inside a 1 km zone, it changes every few
     * seconds as the rider moves, and paying Google to refine a two minute
     * guess on every poll would cost more than the order makes.
     *
     * @param  array<string, mixed>|null  $position
     */
    protected function ridingToKitchenMinutes(Order $order, ?array $position): ?int
    {
        if ($position === null || $order->merchant?->latitude === null) {
            return null;
        }

        // Already there, waiting at the counter.
        if ($order->arrived_at !== null) {
            return 0;
        }

        return $this->minutesAcross($this->geo->distance(
            $position['latitude'],
            $position['longitude'],
            (float) $order->merchant->latitude,
            (float) $order->merchant->longitude,
        ));
    }

    /** Straight-line metres at a deliberately pessimistic town speed. */
    protected function minutesAcross(float $metres): int
    {
        $kmh = max(1.0, (float) config('tracking.average_speed_kmh'));

        return (int) ceil($metres / 1000 / $kmh * 60);
    }

    /** Nobody is helped by "Arriving in 0 min" on food that has not arrived. */
    protected function atLeastOne(?int $minutes): ?int
    {
        return $minutes === null ? null : max(1, $minutes);
    }
}
