<?php

namespace App\Services\LiveState\Routing;

use App\Contracts\RouteProvider;

/**
 * Measures nothing.
 *
 * What runs with no key configured, and in every test that is not about
 * routing. Tracking then shows the rider's position with an estimated time
 * built from the straight line, and no drawn route — which is the behaviour
 * the customer app had before any of this existed.
 */
class NullRoute implements RouteProvider
{
    public function between(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        return null;
    }
}
