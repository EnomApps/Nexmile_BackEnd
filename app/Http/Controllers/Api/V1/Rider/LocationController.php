<?php

namespace App\Http\Controllers\Api\V1\Rider;

use App\Enums\OrderStatus;
use App\Enums\RiderStatus;
use App\Http\Controllers\Controller;
use App\Models\Rider;
use App\Services\Discovery\NearbyMerchantService;
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

        $this->noteArrival($rider, (float) $data['latitude'], (float) $data['longitude']);

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

    /**
     * Record the moment a rider reaches the restaurant.
     *
     * Waiting is paid by the minute, and the only honest way to measure it is
     * from arrival. The alternative — an "I have arrived" button — pays
     * whatever the rider taps, and riders learn that within a shift.
     *
     * Read off pings they already send, so it costs nothing extra and cannot
     * be gamed without actually riding there.
     */
    protected function noteArrival(Rider $rider, float $latitude, float $longitude): void
    {
        $order = $rider->orders()
            ->where('status', OrderStatus::RiderAssigned->value)
            ->whereNull('arrived_at')
            ->with('merchant:id,latitude,longitude')
            ->first();

        if ($order === null || $order->merchant?->latitude === null) {
            return;
        }

        $metres = app(NearbyMerchantService::class)->distance(
            $latitude,
            $longitude,
            (float) $order->merchant->latitude,
            (float) $order->merchant->longitude,
        );

        if ($metres > (float) config('rider_pay.arrival_radius_metres')) {
            return;
        }

        $order->forceFill(['arrived_at' => now()])->save();
    }

    protected function rider(Request $request): Rider
    {
        $rider = $request->user()->rider;

        abort_if($rider === null, 404, 'No rider profile found for this account.');

        return $rider;
    }
}
