<?php

namespace App\Contracts;

/**
 * How far it actually is to drive, as opposed to how far it looks.
 *
 * A restaurant 900 metres away across the Vaigai or the far side of a level
 * crossing is a 1.6 km ride. Straight-line distance says it is close, the
 * customer is told a time built on that, and the promise breaks on arrival.
 */
interface RoadDistanceProvider
{
    /**
     * Road distance in metres from one origin to several destinations.
     *
     * Batched because every provider charges per pair and allows many
     * destinations per request — measuring one restaurant at a time would be
     * twenty round trips for one screen.
     *
     * Null for any destination that could not be measured. Callers fall back
     * to the straight line; a maps outage must never empty the busiest screen
     * in the product.
     *
     * @param  list<array{lat: float, lng: float}>  $destinations
     * @return list<int|null> metres, in the order given
     */
    public function measure(float $fromLat, float $fromLng, array $destinations): array;
}
