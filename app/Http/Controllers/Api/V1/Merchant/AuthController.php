<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Actions\RegisterMerchant;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\LoginRequest;
use App\Http\Requests\Merchant\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * POST /api/v1/merchant/register
     *
     * Creates the owner's user account and the merchant business profile in
     * one transaction. The account starts as `pending` and cannot take orders
     * until an admin verifies KYC (EP2).
     */
    public function register(RegisterRequest $request, RegisterMerchant $registerMerchant): JsonResponse
    {
        $user = $registerMerchant->handle($request->validated());

        return response()->json([
            'message' => 'Registration successful. Your account is pending verification.',
            'data' => [
                'user' => new UserResource($user),
                'token' => $user->createToken('merchant-web')->plainTextToken,
                'token_type' => 'Bearer',
            ],
        ], 201);
    }

    /**
     * POST /api/v1/merchant/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $throttleKey = Str::lower($request->input('identifier')).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json([
                'message' => 'Too many login attempts. Try again in '
                    .RateLimiter::availableIn($throttleKey).' seconds.',
            ], 429);
        }

        $user = User::where($request->identifierField(), $request->input('identifier'))
            ->where('role', UserRole::Merchant)
            ->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            return response()->json([
                'message' => 'These credentials do not match our records.',
            ], 401);
        }

        if ($user->status === UserStatus::Suspended) {
            return response()->json([
                'message' => 'This account has been suspended. Contact support.',
            ], 403);
        }

        RateLimiter::clear($throttleKey);
        $user->load('merchant');

        return response()->json([
            'message' => 'Login successful.',
            'data' => [
                'user' => new UserResource($user),
                'token' => $user->createToken($request->input('device_name', 'merchant-web'))->plainTextToken,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    /**
     * GET /api/v1/merchant/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('merchant');

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }

    /**
     * POST /api/v1/merchant/logout — revokes only the token used for this request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
