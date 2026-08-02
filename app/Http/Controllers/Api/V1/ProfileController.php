<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Profile shared by every role (EP2).
 *
 * Role-specific detail lives behind /rider/profile and /merchant/profile;
 * this covers the fields every account has.
 */
class ProfileController extends Controller
{
    /**
     * Current profile
     *
     * Includes the merchant or rider profile when the account has one.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load(['merchant', 'rider', 'defaultAddress']);

        return response()->json(['data' => new UserResource($user)]);
    }

    /**
     * Update your profile
     *
     * Only name, contact details and language. Role and account status are
     * deliberately not editable here — a user must not be able to promote
     * themselves or lift a suspension.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes', 'nullable', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id)->whereNull('deleted_at'),
            ],
            'phone' => [
                'sometimes', 'nullable', 'string', 'regex:/^[6-9]\d{9}$/',
                Rule::unique('users', 'phone')->ignore($user->id)->whereNull('deleted_at'),
            ],
            'preferred_locale' => ['sometimes', 'in:en,ta,hi'],
        ], [
            'phone.regex' => 'Enter a valid 10-digit Indian mobile number.',
        ]);

        /*
         * Changing a verified contact clears its verification. Otherwise a
         * user could verify one number, swap in another, and appear verified
         * on a number they do not control.
         */
        if (array_key_exists('email', $data) && $data['email'] !== $user->email) {
            $user->forceFill(['email_verified_at' => null]);
        }

        if (array_key_exists('phone', $data) && $data['phone'] !== $user->phone) {
            $user->forceFill(['phone_verified_at' => null]);
        }

        $user->fill($data)->save();

        return response()->json([
            'message' => 'Profile updated.',
            'data' => new UserResource($user->fresh()->load(['merchant', 'rider'])),
        ]);
    }

    /**
     * Delete your account
     *
     * Soft delete, so past orders, invoices and settlement records stay intact
     * for tax and accounting purposes. All sessions are revoked.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        // A merchant with a live storefront cannot vanish mid-service.
        if ($user->role === UserRole::Merchant && $user->merchant?->is_accepting_orders) {
            return response()->json([
                'message' => 'Stop accepting orders before closing your account.',
            ], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Your account has been closed.']);
    }
}
