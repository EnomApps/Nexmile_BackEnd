<?php

return [

    /*
    |--------------------------------------------------------------------------
    | What a rider earns for one delivery
    |--------------------------------------------------------------------------
    | Effort-based rather than a flat fee per order: first mile, waiting, last
    | mile. Within a 1 km radius those three are small and predictable, which
    | is the whole reason this can be advertised honestly — a rider can work
    | out what a job pays before accepting it.
    |
    | Every figure here is snapshotted onto the order when it is delivered, so
    | changing a rate never restates what someone has already been paid.
    */

    'enabled' => env('RIDER_PAY_ENABLED', true),

    /*
     * Rider's position when they accepted, to the restaurant. Flat within the
     * base distance, then per kilometre — a rider who rides two kilometres to
     * reach a kitchen is doing more work than one already outside it.
     */
    'first_mile' => [
        'base' => 8.00,
        'base_metres' => 1000,
        'per_km_beyond' => 6.00,
    ],

    /*
     * Restaurant to customer. The paid part of the job, and the one a rider
     * can see before accepting.
     */
    'last_mile' => [
        'base' => 15.00,
        'base_metres' => 1500,
        'per_km_beyond' => 8.00,
    ],

    /*
     * Waiting at the counter. Free for the first few minutes because a kitchen
     * needs a moment to hand over, then paid — a rider standing in a shop is
     * not earning anywhere else.
     *
     * Measured from a geofenced arrival, not from acceptance. The gap between
     * accepting and collecting is travel plus waiting, and paying for it would
     * pay a rider for their own journey twice.
     */
    /*
     * How close a rider has to be for the system to call it an arrival.
     * Generous, because GPS in a narrow street is not precise and a rider
     * standing at a counter can read fifty metres off — and under-detecting
     * costs a rider money they earned.
     */
    'arrival_radius_metres' => 80,

    'waiting' => [
        'free_minutes' => 5,
        'per_minute' => 1.00,
        // A rider cannot be left earning indefinitely because a kitchen forgot
        // to hand over, and nor can the platform be billed for it. Past this,
        // it is a support problem rather than a payout one.
        'max_minutes' => 30,
    ],

    /*
     * The floor. A short hop next door still costs a rider a trip, and a
     * number a rider can rely on is worth more for recruiting than a slightly
     * higher average they cannot predict.
     */
    'minimum_payout' => 25.00,

    /*
     * Surcharges, added after the minimum is applied.
     *
     * Capped as a share of what the order itself earns. A flat peak-plus-rain
     * bonus on a small order can cost more than the order makes — and it lands
     * exactly on the wet Friday evening when the most orders are running.
     */
    'incentives' => [
        'peak' => 10.00,
        'peak_hours' => [
            // 24h, local. Lunch and dinner rushes.
            ['12:00', '14:00'],
            ['19:00', '22:00'],
        ],
        'bad_weather' => 5.00,

        /*
         * Flipped by ops when it is raining. A per-order flag would mean
         * tagging five hundred orders by hand on exactly the evening nobody
         * has time — and the weather is the same for every rider in the zone.
         */
        'bad_weather_active' => env('RIDER_PAY_BAD_WEATHER', false),
        'max_share_of_order_margin' => 0.60,
    ],

];
