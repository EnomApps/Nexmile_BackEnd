<?php

namespace App\Http\Controllers\Api\V1\Rider;

use App\Enums\RiderStatus;
use App\Http\Controllers\Controller;
use App\Models\Rider;
use App\Services\LiveState\RiderLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rider GPS pings (EP9).
 *
 * Positions go to a Redis geo set, not the orders table — pings arrive every
 * few seconds per rider, which no relational table should absorb.
 *
 * The ping doubles as a heartbeat. A rider whose app is killed or loses signal
 * drops out of dispatch on its own once the TTL lapses, so a phone in a pocket
 * cannot hold an order nobody is riding towards.
 */
class LocationController extends Controller
{
    public function __construct(protected RiderLocationService $locations) {}

    /**
     * Send the rider's position
     *
     * Called every few seconds while on duty. Cheap on purpose.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_metres' => ['sometimes', 'nullable', 'numeric', 'between:0,10000'],
        ]);

        $rider = $this->rider($request);

        if ($rider->duty_status === RiderStatus::Offline) {
            // Not an error the app should surface — it just stops tracking
            // someone who has clocked off.
            return response()->json(['message' => 'You are offline.', 'data' => ['tracking' => false]]);
        }

        rescue(fn () => $this->locations->updateLocation(
            $rider->id,
            (int) ($rider->zone_id ?? 0),
            (float) $data['latitude'],
            (float) $data['longitude'],
        ), report: true);

        /*
         * Also written to MySQL, but only as a fallback for the board after a
         * Redis restart. This is a single indexed row update per ping, not a
         * history — the trail is not worth the write volume.
         */
        $rider->forceFill([
            'last_latitude' => $data['latitude'],
            'last_longitude' => $data['longitude'],
            'last_location_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Location updated.',
            'data' => ['tracking' => true],
        ]);
    }

    protected function rider(Request $request): Rider
    {
        $rider = $request->user()->rider;

        abort_if($rider === null, 404, 'No rider profile found for this account.');

        return $rider;
    }
}
