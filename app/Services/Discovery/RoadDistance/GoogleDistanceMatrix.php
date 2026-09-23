<?php

namespace App\Services\Discovery\RoadDistance;

use App\Contracts\RoadDistanceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google's Distance Matrix, batched.
 *
 * Billed per origin-destination pair, so the whole design is about asking as
 * few times as possible: one origin, many destinations, one request, and a
 * cache in front of it.
 *
 * Two-wheeler routing is not a mode Google offers in India, so this asks for
 * driving. It overstates a little — a scooter takes lanes a car cannot — and
 * overstating a delivery time is the safe direction to be wrong in.
 */
class GoogleDistanceMatrix implements RoadDistanceProvider
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/distancematrix/json';

    public function measure(float $fromLat, float $fromLng, array $destinations): array
    {
        $unknown = array_fill(0, count($destinations), null);

        if ($destinations === []) {
            return [];
        }

        $key = config('services.google_maps.key');

        if (empty($key)) {
            return $unknown;
        }

        try {
            $response = Http::timeout((int) config('discovery.road_distance.timeout_seconds', 4))
                ->get(self::ENDPOINT, [
                    'origins' => $fromLat.','.$fromLng,
                    'destinations' => collect($destinations)
                        ->map(fn (array $d) => $d['lat'].','.$d['lng'])
                        ->join('|'),
                    'mode' => 'driving',
                    'units' => 'metric',
                    'key' => $key,
                ]);

            if (! $response->successful()) {
                return $unknown;
            }

            $body = $response->json();

            /*
             * Google answers 200 with a status field of its own. An expired
             * key or an exhausted quota arrives looking like a success, and
             * treating it as one would mean silently measuring nothing for
             * however long it takes somebody to notice.
             */
            if (($body['status'] ?? null) !== 'OK') {
                Log::warning('Distance Matrix refused the request', [
                    'status' => $body['status'] ?? null,
                    'error' => $body['error_message'] ?? null,
                ]);

                return $unknown;
            }

            $elements = $body['rows'][0]['elements'] ?? [];

            return array_map(function (int $i) use ($elements) {
                $element = $elements[$i] ?? null;

                // ZERO_RESULTS is a real answer: nowhere to drive. Left null
                // so the straight line is used rather than pretending.
                if (($element['status'] ?? null) !== 'OK') {
                    return null;
                }

                return isset($element['distance']['value'])
                    ? (int) $element['distance']['value']
                    : null;
            }, array_keys($destinations));
        } catch (\Throwable $e) {
            // A dropped connection must not empty a customer's home screen.
            Log::warning('Distance Matrix unreachable', ['error' => $e->getMessage()]);

            return $unknown;
        }
    }
}
