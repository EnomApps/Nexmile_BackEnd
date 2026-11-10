<?php

namespace App\Services\LiveState\Routing;

use App\Contracts\RouteProvider;

/**
 * Measures nothing.
 *
 * What runs with no key configured, and in every test that is not about
 * routing. Tracking then shows the rider's position with an estimated time
 * built from the straight line, and no drawn route — which is the behaviour
 * the customer app had before any of this existed. The rider app draws a
 * dotted line to the stop and no turns.
 */
class NullRoute implements RouteProvider
{
    /**
     * Nothing behind it, said plainly.
     *
     * The rider app needs to tell "not switched on yet" from "no road to
     * here", and this is the first of those.
     */
    public function configured(): bool
    {
        return false;
    }

    public function between(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        return null;
    }

    public function navigation(
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng,
        ?string $language = null,
    ): ?array {
        return null;
    }
}
