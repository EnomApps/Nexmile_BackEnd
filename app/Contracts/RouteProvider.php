<?php

namespace App\Contracts;

/**
 * The line a rider will actually ride, and how long it will take.
 *
 * Distinct from RoadDistanceProvider, which answers "how far" for many
 * destinations at once so a discovery page can be corrected in one request.
 * This answers "which way, and how long" for exactly one pair, because it
 * exists to be drawn on a map the customer is watching.
 */
interface RouteProvider
{
    /**
     * One route, or null when it cannot be worked out.
     *
     * Null is an ordinary answer, not an error: no key, no network, nowhere to
     * ride. The caller falls back to a straight line and an estimate, because
     * a customer watching a delivery must never be shown a blank screen
     * because a maps bill went unpaid.
     *
     * `polyline` is Google's encoded overview polyline — the string form every
     * map SDK already knows how to draw, rather than a list of coordinates
     * that would be twenty times the size on the wire.
     *
     * @return array{polyline: string, duration_seconds: int, distance_metres: int}|null
     */
    public function between(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array;
}
