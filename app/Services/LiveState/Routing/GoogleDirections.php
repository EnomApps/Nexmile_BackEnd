<?php

namespace App\Services\LiveState\Routing;

use App\Contracts\RouteProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Directions, for the line drawn on the customer's map.
 *
 * Billed per request, and a customer watching a delivery polls every few
 * seconds — so the caching in TrackingService is not an optimisation here, it
 * is the difference between a few paise and a few hundred rupees per order.
 * Nothing should call this directly.
 *
 * Asks for driving. Two-wheeler routing is not a mode Google offers in India,
 * so this overstates a little — a scooter takes lanes a car cannot — and
 * overstating an arrival time is the safe direction to be wrong in.
 */
class GoogleDirections implements RouteProvider
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/directions/json';

    public function between(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        $key = config('services.google_maps.key');

        if (empty($key)) {
            return null;
        }

        try {
            $response = Http::timeout((int) config('tracking.route.timeout_seconds', 4))
                ->get(self::ENDPOINT, [
                    'origin' => $fromLat.','.$fromLng,
                    'destination' => $toLat.','.$toLng,
                    'mode' => 'driving',
                    'departure_time' => 'now',
                    'key' => $key,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();

            /*
             * Google answers 200 with a status field of its own. An expired
             * key, a wrong IP restriction or an exhausted quota all arrive
             * looking like successes, and treating them as such would mean
             * drawing no route for however long it took somebody to notice.
             */
            if (($body['status'] ?? null) !== 'OK') {
                Log::warning('Directions refused the request', [
                    'status' => $body['status'] ?? null,
                    'error' => $body['error_message'] ?? null,
                ]);

                return null;
            }

            $route = $body['routes'][0] ?? null;
            $leg = $route['legs'][0] ?? null;
            $polyline = $route['overview_polyline']['points'] ?? null;

            if ($polyline === null || $leg === null) {
                return null;
            }

            /*
             * duration_in_traffic when it is there. It needs departure_time,
             * which is sent above, and it is the number the customer is
             * actually waiting out — plain duration is a free-flowing road at
             * seven in the evening.
             */
            $seconds = $leg['duration_in_traffic']['value'] ?? $leg['duration']['value'] ?? null;

            if ($seconds === null) {
                return null;
            }

            return [
                'polyline' => $polyline,
                'duration_seconds' => (int) $seconds,
                'distance_metres' => (int) ($leg['distance']['value'] ?? 0),
            ];
        } catch (\Throwable $e) {
            // A dropped connection must not break a map somebody is watching.
            Log::warning('Directions unreachable', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
