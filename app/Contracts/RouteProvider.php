<?php

namespace App\Contracts;

/**
 * The line a rider will actually ride, and how long it will take.
 *
 * Distinct from RoadDistanceProvider, which answers "how far" for many
 * destinations at once so a discovery page can be corrected in one request.
 * This answers "which way, and how long" for exactly one pair, because it
 * exists to be drawn on a map somebody is watching.
 *
 * Two shapes, because two screens need different things from the same call.
 * The customer's map needs one line and an arrival time. The rider's
 * navigation needs the turns as well, and they cost the same request, so
 * nothing is saved by pretending otherwise — they are kept apart only so the
 * customer's poll does not pay to parse turn text nobody will read.
 */
interface RouteProvider
{
    /**
     * Is there anything behind this provider?
     *
     * Asked so a caller can tell "nobody configured a maps key" from "there
     * is no road between these two points". Both come back as null below, and
     * the rider app shows the first as a service that is not switched on yet
     * and the second as a place it cannot route to.
     */
    public function configured(): bool;

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

    /**
     * The same route, with the turns, for navigating inside the rider app.
     *
     * `$language` is the rider's own, so "Turn right onto 3rd Street" arrives
     * in a language they read rather than in ours. Instructions come back as
     * plain text: the provider's markup is stripped here rather than in the
     * app, because an app that renders our HTML is an app we can break by
     * changing providers.
     *
     * Steps are in riding order. `maneuver` may be null — the first step and
     * the last one are often "head north" and "you have arrived", which are
     * not turns, and an app that assumes an icon for every step draws a
     * wrong one.
     *
     * @return array{polyline: string, duration_seconds: int, distance_metres: int, steps: array<int, array{instruction: string, maneuver: string|null, distance_metres: int, duration_seconds: int, end_latitude: float, end_longitude: float}>}|null
     */
    public function navigation(
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng,
        ?string $language = null,
    ): ?array;
}
