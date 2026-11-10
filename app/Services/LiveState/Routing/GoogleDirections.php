<?php

namespace App\Services\LiveState\Routing;

use App\Contracts\RouteProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Directions, for the line on the customer's map and the turns in the
 * rider's navigation.
 *
 * Billed per request, and a customer watching a delivery polls every few
 * seconds — so the caching in TrackingService is not an optimisation here, it
 * is the difference between a few paise and a few hundred rupees per order.
 * The rider side is held down by NavigationService for the same reason.
 * Nothing should call this directly.
 *
 * Asks for driving. Two-wheeler routing is not a mode the Directions API
 * offers, so this overstates a little — a scooter takes lanes a car cannot —
 * and overstating an arrival time is the safe direction to be wrong in. The
 * newer Routes API does have TWO_WHEELER and is the upgrade worth making once
 * there is a key to test it against.
 */
class GoogleDirections implements RouteProvider
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/directions/json';

    public function configured(): bool
    {
        return ! empty(config('services.google_maps.key'));
    }

    public function between(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        $leg = $this->leg($fromLat, $fromLng, $toLat, $toLng);

        if ($leg === null) {
            return null;
        }

        return [
            'polyline' => $leg['polyline'],
            'duration_seconds' => $leg['duration_seconds'],
            'distance_metres' => $leg['distance_metres'],
        ];
    }

    public function navigation(
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng,
        ?string $language = null,
    ): ?array {
        $leg = $this->leg($fromLat, $fromLng, $toLat, $toLng, $language);

        if ($leg === null) {
            return null;
        }

        return [
            'polyline' => $leg['polyline'],
            'duration_seconds' => $leg['duration_seconds'],
            'distance_metres' => $leg['distance_metres'],
            'steps' => $this->steps($leg['raw']),
        ];
    }

    /**
     * One leg, parsed, or null when there is nothing usable in the answer.
     *
     * @return array{polyline: string, duration_seconds: int, distance_metres: int, raw: array<string, mixed>}|null
     */
    protected function leg(
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng,
        ?string $language = null,
    ): ?array {
        $key = config('services.google_maps.key');

        if (empty($key)) {
            return null;
        }

        try {
            $response = Http::timeout((int) config('tracking.route.timeout_seconds', 4))
                ->get(self::ENDPOINT, array_filter([
                    'origin' => $fromLat.','.$fromLng,
                    'destination' => $toLat.','.$toLng,
                    'mode' => 'driving',
                    'departure_time' => 'now',
                    'language' => $language,
                    'key' => $key,
                ], fn ($value) => $value !== null));

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
                'raw' => $leg,
            ];
        } catch (\Throwable $e) {
            // A dropped connection must not break a map somebody is watching.
            Log::warning('Directions unreachable', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The turns, in riding order.
     *
     * @param  array<string, mixed>  $leg
     * @return array<int, array{instruction: string, maneuver: string|null, distance_metres: int, duration_seconds: int, end_latitude: float, end_longitude: float}>
     */
    protected function steps(array $leg): array
    {
        $steps = [];

        foreach ($leg['steps'] ?? [] as $step) {
            $end = $step['end_location'] ?? null;

            /*
             * A step with no end point cannot be navigated to. Skipped rather
             * than passed on with zeroes, which would put a turn in the Gulf
             * of Guinea and send the rider's camera with it.
             */
            if (! isset($end['lat'], $end['lng'])) {
                continue;
            }

            $steps[] = [
                'instruction' => $this->plainText($step['html_instructions'] ?? ''),
                // Absent on the first and last steps, which are not turns.
                'maneuver' => $step['maneuver'] ?? null,
                'distance_metres' => (int) ($step['distance']['value'] ?? 0),
                'duration_seconds' => (int) ($step['duration']['value'] ?? 0),
                'end_latitude' => (float) $end['lat'],
                'end_longitude' => (float) $end['lng'],
            ];
        }

        return $steps;
    }

    /**
     * Google's instructions are HTML — street names in bold tags, and a div
     * with no separator before "Destination will be on the left".
     *
     * Stripped here, not in the app. Tags become a space rather than nothing,
     * or "Turn right onto<div>3rd Street" runs two words together, and
     * entities are decoded so a rider reads "Gandhi & Co" rather than
     * "Gandhi &amp; Co".
     */
    protected function plainText(string $html): string
    {
        $text = preg_replace('/<[^>]*>/', ' ', $html) ?? '';

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
