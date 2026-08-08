<?php

return [

    /*
     * How riders get orders: 'board' or 'push'.
     *
     * **Board** is what runs today. Ready orders appear on a list that nearby
     * on-duty riders poll, and the first to accept wins — settled by a
     * conditional UPDATE, so two riders tapping at the same instant cannot
     * both get it.
     *
     * **Push** — offer to the single nearest rider, wait, pass it on if they
     * decline or time out — gives better allocation at volume, but it only
     * works with a queue worker running to expire offers. Without one, a
     * declined offer strands the order and nobody is looking at it. There is
     * no worker on this deployment, and an order that silently stalls is far
     * worse than a rider choosing from a short list.
     *
     * DispatchQueueService already has the offer, lock and TTL primitives for
     * push when volume justifies running a worker.
     */
    'mode' => env('DISPATCH_MODE', 'board'),

    /*
     * How far a rider will be shown orders from. Slightly wider than the 1 km
     * delivery radius: the rider is travelling to the *restaurant* first, and
     * one a little further out is still worth offering.
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
