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

];
