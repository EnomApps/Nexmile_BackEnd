<?php

return [

    /*
     * Nexmile is a 1 km hyperlocal service. This is the promise, not a tuning
     * knob — widening it changes what the product is, and the delivery fee and
     * rider economics are both built around it.
     *
     * A zone may override it: `zones.radius_metres` wins when the customer's
     * point falls inside one, which is how ops open up a sparse area without a
     * deploy.
     */
    'radius_metres' => env('DISCOVERY_RADIUS_METRES', 1000),

    /*
     * The ceiling a zone override may reach. Beyond this a "hyperlocal"
     * delivery stops being one — a rider on a bicycle covers 3 km slowly
     * enough that the food arrives cold.
     */
    'max_radius_metres' => 3000,

    'per_page' => 20,

    /*
     * A ceiling on rows pulled out of the bounding box, not a page size. The
     * box is already small at these radii; this exists only so a
     * misconfigured radius cannot load the merchants table into memory.
     * Reaching it means the box is wrong, not that the town is busy.
     */
    'max_candidates' => 1000,

    /*
     * Closed restaurants are still listed, ranked below open ones. A customer
     * in a small town would otherwise see an empty screen at 3pm and conclude
     * Nexmile does not work here, rather than that lunch service ended.
     */
    'show_closed' => true,

    /*
     * The "near and fast" chip on the home screen. Defined here rather than in
     * the app, which asked us to own it — two apps inventing their own
     * distance and time rule would disagree with each other and with us.
     *
     * A restaurant qualifies inside half the search radius with a prep time at
     * or under this, and only while it is open.
     */
    'near_and_fast_prep_minutes' => env('NEAR_AND_FAST_PREP_MINUTES', 30),

    /*
     * How many matching dishes a search result names.
     *
     * Two or three is the point — enough to show why the restaurant is in the
     * list and what it costs. A card listing every match stops being a card.
     */
    'matched_dishes_shown' => 3,

    /*
     * How long the home screen's banners, cuisines and collection tiles are
     * held.
     *
     * They are identical for every customer and change a few times a week, but
     * are fetched on every app open. The cache is cleared the moment an admin
     * edits any of them, so this ceiling only matters if something changes the
     * rows without going through the admin screen — a database edit, or a
     * campaign window opening on its own.
     *
     * That second case is the real reason it is a minute rather than an hour:
     * a banner scheduled to start at 6pm should appear at about 6pm.
     */
    'home_cache_seconds' => env('HOME_CACHE_SECONDS', 60),

    /*
     * How far it is to ride, as opposed to how far it looks.
     *
     * Madurai has a river and several level crossings. A restaurant 900 metres
     * away in a straight line can be a 1.6 km ride, and a customer told "900 m,
     * 25 minutes" on that basis is being told something we cannot keep.
     *
     * Measured only for the restaurants on the page being shown — never for
     * every candidate. A 1 km search can cross a hundred restaurants and the
     * provider bills per pair.
     */
    'road_distance' => [

        /*
         * Off by default, including in production until somebody turns it on
         * with a key in place. Discovery works without it exactly as it did
         * before — this corrects an answer, it must never withhold one.
         */
        'enabled' => env('ROAD_DISTANCE_ENABLED', false),

        // 'google' needs services.google_maps.key. Anything else measures
        // nothing, and every caller falls back to the straight line.
        'driver' => env('ROAD_DISTANCE_DRIVER', 'null'),

        /*
         * A month. Roads do not move, and the answer for a given street and
         * restaurant is the same in March as in January.
         */
        'ttl_seconds' => env('ROAD_DISTANCE_TTL', 2592000),

        /*
         * A failure is remembered for minutes, not weeks. Otherwise one bad
         * afternoon of API errors gets cached over a road network that has
         * not changed since the bridge was built.
         */
        'failure_ttl_seconds' => 300,

        /*
         * Decimal places the customer's position is rounded to before it
         * becomes a cache key. Three is about a hundred metres.
         *
         * A deliberate inaccuracy. Exact coordinates mean a fresh paid lookup
         * for every few metres a phone drifts, and a cache that never hits is
         * one that only costs money. Two customers on the same street share an
         * answer, which over a kilometre is well inside the routing's own
         * error.
         */
        'coordinate_precision' => 3,

        // Google's ceiling for destinations in one Distance Matrix request.
        'max_destinations_per_call' => 25,

        /*
         * Short, because this runs inside the busiest screen in the product.
         * A slow answer is worth less than a fast straight line.
         */
        'timeout_seconds' => 4,
    ],

];
