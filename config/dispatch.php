<?php

return [

    /*
     * How riders get orders: 'board' or 'push'.
     *
     * **Board** pulls. Ready orders appear on a list that nearby on-duty
     * riders poll, and the first to accept wins — settled by a conditional
     * UPDATE, so two riders tapping at the same instant cannot both get it.
     * It works with no queue worker and no Redis, which is why it shipped
     * first, and it stops working the moment riders are actually riding:
     * nobody reads a list while on a bike.
     *
     * **Push** rings. The order is offered to the riders nearest the
     * restaurant, their phones ring with a full-screen card and a countdown,
     * and the first to accept wins — settled by the same conditional UPDATE.
     * Unanswered offers lapse and the order goes out again, wider.
     *
     * Push needs a queue worker running. Every timeout in it — the offer
     * window, the next wave, giving up — is a delayed job, and with no worker
     * the first wave goes out and the order sits there forever with nobody
     * looking at it. Check `php artisan queue:work` is running under
     * systemd before switching this, and see docs/DISPATCH.md.
     */
    'mode' => env('DISPATCH_MODE', 'board'),

    /*
     * When the hunt for a rider starts: 'accepted' or 'ready'.
     *
     * 'accepted' — the moment the kitchen takes the order — is what the spec
     * asks for and what Swiggy and Zomato do: the rider travels while the
     * food cooks and arrives as it comes out of the kitchen, instead of
     * afterwards. It costs offers made for food that is later cancelled.
     *
     * 'ready' is the old board behaviour, kept because for a kitchen with a
     * four-minute prep time the rider would arrive before the food either
     * way, and an offer made and withdrawn is a phone rung for nothing.
     *
     * The better answer is neither: start dispatch at (prep time − travel
     * time). That needs prep estimates we do not yet trust, so it is noted
     * in docs/DISPATCH.md rather than built on a guess.
     */
    'start_at' => env('DISPATCH_START_AT', 'accepted'),

    /*
     * The waves. Each goes out to riders within the radius who were not
     * offered an earlier wave, and lapses after `seconds`.
     *
     * These radii are the ones in the client's spec, which are Swiggy's — and
     * Swiggy delivers 5–7 km for a fee to match. Nexmile delivers 1 km for
     * about ₹29. A rider 6 km from the restaurant is a twenty-minute ride
     * before the delivery starts, which no rider will take twice and which
     * arrives cold if they do. Expect to cut wave 3, and possibly wave 2,
     * once there is real acceptance data — the radii are env-settable for
     * exactly that reason.
     *
     * A short list of waves is not a weaker system than a long one. With the
     * rider pool at launch, wave 1 alone is the spec's own recommendation:
     * "offer to all idle riders within 3–5 km at once, first accept wins".
     */
    'waves' => [
        [
            'radius_metres' => env('DISPATCH_WAVE1_RADIUS', 2000),
            'riders' => env('DISPATCH_WAVE1_RIDERS', 5),
            'seconds' => env('DISPATCH_WAVE1_SECONDS', 30),
        ],
        [
            'radius_metres' => env('DISPATCH_WAVE2_RADIUS', 4000),
            'riders' => env('DISPATCH_WAVE2_RIDERS', 10),
            'seconds' => env('DISPATCH_WAVE2_SECONDS', 30),
        ],
        [
            'radius_metres' => env('DISPATCH_WAVE3_RADIUS', 6000),
            'riders' => env('DISPATCH_WAVE3_RIDERS', 15),
            'seconds' => env('DISPATCH_WAVE3_SECONDS', 30),
        ],
    ],

    /*
     * Offer the same order to a rider again on a later wave?
     *
     * False. A rider who ignored it thirty seconds ago is not more likely to
     * take it now, and ringing the same phone three times for one order is
     * how a rider turns notifications off — which costs every future order,
     * not just this one.
     */
    'reoffer_to_same_rider' => false,

    /*
     * Let it ring out three times in a row and the rider is taken off duty.
     *
     * Not a punishment — a phone in a pocket at the bottom of a bag. Every
     * wave that includes a rider who is not there is thirty seconds the
     * customer waits for nothing, and dispatch has no other way to tell that
     * rider apart from one who is deciding.
     *
     * Declining does not count. A rider who taps no has answered, and a
     * system that punishes an honest decline teaches riders to let it ring
     * instead. Zero disables this.
     */
    'auto_offline_after_misses' => env('DISPATCH_AUTO_OFFLINE_MISSES', 3),

    /*
     * How far a rider will be shown orders from on the **board**. Slightly
     * wider than the 1 km delivery radius: the rider is travelling to the
     * *restaurant* first, and one a little further out is still worth
     * offering.
     */
    'board_radius_metres' => env('DISPATCH_BOARD_RADIUS', 2000),

    'board_limit' => 20,

    /*
     * One order at a time. At 1 km, batching two drops saves a few minutes and
     * costs the second customer their food going cold in a bag. Raise this
     * only with evidence.
     */
    'max_concurrent_orders_per_rider' => 1,

    /*
     * A rider whose app has not pinged within this window is treated as gone,
     * whatever their duty status says. Matches RiderLocationService::HEARTBEAT_TTL.
     */
    'presence_ttl_seconds' => 60,

];
