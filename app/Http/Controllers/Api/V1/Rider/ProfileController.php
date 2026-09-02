<?php

namespace App\Http\Controllers\Api\V1\Rider;

use App\Enums\RiderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RiderResource;
use App\Models\Rider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rider profile (EP2).
 *
 * Document submission and KYC review are a separate ticket; this covers the
 * profile itself and going on or off duty.
 */
class ProfileController extends Controller
{
    /**
     * Rider profile
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new RiderResource($this->profile($request)),
        ]);
    }

    /**
     * Update rider profile
     *
     * KYC fields are not editable here. Once documents are submitted they are
     * changed through the KYC endpoints, so an approved rider cannot quietly
     * swap in a different licence afterwards.
     */
    public function update(Request $request): JsonResponse
    {
        $rider = $this->profile($request);

        $data = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:255'],
            'date_of_birth' => ['sometimes', 'date', 'before:-18 years'],
            // Walking is a real way to work inside a 1 km radius, and excluding it
            // narrows the pool for no reason in exactly the market where the
            // distances are short enough.
            'vehicle_type' => ['sometimes', Rule::in(config('kyc.vehicle_types'))],
            'vehicle_number' => ['sometimes', 'nullable', 'string', 'max:15'],
        ], [
            'date_of_birth.before' => 'Riders must be at least 18 years old.',
        ]);

        $rider->update($data);

        return response()->json([
            'message' => 'Profile updated.',
            'data' => new RiderResource($rider->fresh()),
        ]);
    }

    /**
     * Go on or off duty
     *
     * A rider can only go available with verified KYC and current documents —
     * dispatching someone with a lapsed licence or insurance is a legal
     * exposure, not a paperwork detail.
     */
    public function setDutyStatus(Request $request): JsonResponse
    {
        $rider = $this->profile($request);

        $data = $request->validate([
            'duty_status' => ['required', 'in:offline,available,on_break'],
        ]);

        $requested = RiderStatus::from($data['duty_status']);

        // One source of the refusal wording, so what the app is told here
        // matches the offline_reason it was already showing.
        if ($requested === RiderStatus::Available && ($reason = $rider->offlineReason()) !== null) {
            return response()->json(['message' => $reason], 403);
        }

        /*
         * A rider carrying an order cannot simply clock off. The order would
         * keep their rider_id, sit in flight, and nobody would be accountable
         * for food that is already in a bag.
         *
         * Before collection they can hand it back to the board; after, it has
         * to be delivered or a human has to get involved.
         */
        if ($requested !== RiderStatus::Available && $rider->orders()->active()->exists()) {
            return response()->json([
                'message' => 'Finish your delivery first, or hand it back before you have collected it.',
            ], 422);
        }

        // on_order is set by dispatch, never by the rider.
        $rider->update(['duty_status' => $requested]);

        return response()->json([
            'message' => 'Duty status updated.',
            'data' => new RiderResource($rider->fresh()),
        ]);
    }

    /**
     * A rider account always has a profile row; create it lazily on first use
     * so an OTP signup does not have to know about this table.
     */
    private function profile(Request $request): Rider
    {
        $user = $request->user();

        return $user->rider ?? Rider::create([
            'user_id' => $user->id,
            'full_name' => $user->name,
        ]);
    }
}
