<?php

namespace App\Services\Discovery\RoadDistance;

use App\Contracts\RoadDistanceProvider;

/**
 * Measures nothing, so every caller falls back to the straight line.
 *
 * The default, and what runs in tests and on any environment without a Maps
 * key. Discovery works exactly as it did before road distance existed — which
 * is the point: this feature improves an answer, it must never be able to
 * withhold one.
 */
class NullRoadDistance implements RoadDistanceProvider
{
    public function measure(float $fromLat, float $fromLng, array $destinations): array
    {
        return array_fill(0, count($destinations), null);
    }
}
