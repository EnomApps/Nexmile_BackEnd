<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Merchant business profile (EP2).
 *
 * KYC fields are read-only here — see the KYC endpoints. Letting a verified
 * merchant edit their own FSSAI number would make verification meaningless.
 */
class ProfileController extends Controller
{
    /**
     * Business profile
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new MerchantResource($request->user()->merchant),
        ]);
    }

    /**
     * Update business profile
     */
    public function update(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;

        abort_if($merchant === null, 404, 'No business profile found for this account.');

        $data = $request->validate([
            'business_name' => ['sometimes', 'string', 'max:255'],
            'owner_name' => ['sometimes', 'string', 'max:255'],
            'business_phone' => ['sometimes', 'nullable', 'string', 'regex:/^[6-9]\d{9}$/'],
            'business_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'address_line1' => ['sometimes', 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:255'],
            'state' => ['sometimes', 'string', 'max:255'],
            'pincode' => ['sometimes', 'string', 'regex:/^[1-9]\d{5}$/'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'avg_prep_time_minutes' => ['sometimes', 'integer', 'between:1,120'],
            'packaging_fee' => ['sometimes', 'numeric', 'between:0,500'],
            'min_order_value' => ['sometimes', 'numeric', 'between:0,10000'],
            'supports_pickup' => ['sometimes', 'boolean'],
        ], [
            'business_phone.regex' => 'Enter a valid 10-digit Indian mobile number.',
            'pincode.regex' => 'Enter a valid 6-digit PIN code.',
        ]);

        $merchant->update($data);

        return response()->json([
            'message' => 'Business profile updated.',
            'data' => new MerchantResource($merchant->fresh()),
        ]);
    }

    /**
     * Open or close the storefront
     *
     * Refuses to open until KYC is verified and the FSSAI licence is current —
     * an expired food licence is a legal problem, not a warning.
     */
    public function setAcceptingOrders(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;

        abort_if($merchant === null, 404, 'No business profile found for this account.');

        $data = $request->validate([
            'is_accepting_orders' => ['required', 'boolean'],
        ]);

        if ($data['is_accepting_orders']) {
            if (! $merchant->isKycVerified()) {
                return response()->json([
                    'message' => 'Your account is still being verified.',
                ], 403);
            }

            if (! $merchant->hasValidFssai()) {
                return response()->json([
                    'message' => 'Your FSSAI licence is missing or expired. Update it before accepting orders.',
                ], 403);
            }
        }

        $merchant->update($data);

        return response()->json([
            'message' => $data['is_accepting_orders']
                ? 'You are now accepting orders.'
                : 'You have stopped accepting orders.',
            'data' => new MerchantResource($merchant->fresh()),
        ]);
    }
}
