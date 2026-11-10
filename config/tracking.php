<?php

/*
|--------------------------------------------------------------------------
| Live order tracking
|--------------------------------------------------------------------------
| The map a customer watches while their food is on its way.
|
| Two things are expensive here and neither is obvious. A customer polls every
| few seconds for as long as the delivery lasts, so anything computed per
| request is computed hundreds of times per order. And the rider app pings its
| position every ten seconds while carrying, which is a write rate no
| relational table should absorb — positions live in Redis, latest only.
*/

return [

    /*
     * The drawn route, from Google Directions.
     *
     * Off by default. Without it the map still shows the rider moving and an
     * estimated arrival built from the straight line — the customer app
     * behaves as it did before routes existed, rather than showing nothing.
     */
    'route' => [

        'enabled' => env('TRACKING_ROUTE_ENABLED', false),

        // 'google' needs services.google_maps.key, on a key restricted to the
        // backend's IP and to Directions. Anything else draws no route.
        'driver' => env('TRACKING_ROUTE_DRIVER', 'null'),

        'timeout_seconds' => 4,

        /*
         * How long a computed route is reused.
         *
         * A customer polling every 8 seconds over a 15 minute delivery is
         * ~110 requests. At one Directions call each that is real money for a
         * line that barely changes; at one per minute it is fifteen.
         */
        'ttl_seconds' => env('TRACKING_ROUTE_TTL', 45),

        /*
         * Recompute early when the rider has moved this far, whatever the TTL
         * says. Within a 1 km delivery, 200 m is a quarter of the journey —
         * far enough that the old line is visibly wrong and the arrival time
         * built on it is stale.
         */
        'recompute_after_metres' => env('TRACKING_ROUTE_MOVED', 200),

        /*
         * A failure is remembered briefly so a maps outage does not become a
         * Directions call on every poll from every customer at once.
         */
        'failure_ttl_seconds' => 60,
    ],

    /*
     * Fallback speed when there is no route to read a duration from.
     *
     * Deliberately pessimistic for a scooter: this is town traffic with
     * junctions and a handover at the end, not an open road. An arrival time
     * that proves early is a pleasant surprise; one that proves late is a
     * support call.
     */
    'average_speed_kmh' => env('TRACKING_AVERAGE_SPEED', 18),

    /*
     * Collecting from the counter: parking, finding the bag, checking the
     * code. Added to any estimate made before the food is picked up, because
     * leaving it out is how a fifteen minute promise becomes eighteen.
     */
    'handover_minutes' => 3,

    /*
     * After this, the customer app shows "location updating" rather than a
     * rider frozen mid-street. Published in the tracking payload so the apps
     * and the backend cannot drift to different ideas of stale.
     */
    'stale_after_seconds' => 45,

    /*
     * Pings older than this are refused outright. A phone that buffered an
     * hour of fixes in a tunnel must not replay them on reconnect and walk
     * the rider backwards across the customer's map.
     */
    'max_ping_age_seconds' => 120,
];
