<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\OtpRequestRequest;
use App\Http\Requests\Auth\OtpVerifyRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\OtpService;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Mobile OTP login for every role (EP2).
 *
 * Merchants and admins may also sign in with a password through the merchant
 * endpoints; both paths issue the same token pair.
 */
class OtpAuthController extends Controller
{
    /**
     * POST /api/v1/auth/otp/request
     */
    public function request(OtpRequestRequest $request, OtpService $otp): JsonResponse
    {
        $phone = $request->string('phone')->toString();

        // Per-IP ceiling on top of the per-phone limits, so one source cannot
        // enumerate numbers or run up an SMS bill.
        $key = 'otp-request:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 20)) {
            return response()->json([
                'message' => 'Too many requests. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ], 429);
        }

        RateLimiter::hit($key, 3600);

        $code = $otp->request(
            $phone,
            $request->string('intended_role', 'customer')->toString(),
            $request->ip(),
        );

        return response()->json([
            'message' => 'A verification code has been sent to your mobile number.',
            'data' => [
                'phone' => $phone,
                'expires_in' => (int) config('otp.ttl_seconds'),
                'resend_after' => (int) config('otp.resend_cooldown_seconds'),
                // Only ever present outside production, so the Flutter app can
                // be developed without an SMS gateway.
                'debug_code' => app()->environment(['local', 'testing'])
                    ? config('otp.fixed_code')
                    : null,
            ],
        ], $code->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * POST /api/v1/auth/otp/verify
     */
    public function verify(OtpVerifyRequest $request, OtpService $otp, TokenService $tokens): JsonResponse
    {
        $user = $otp->verify(
            $request->string('phone')->toString(),
            $request->string('code')->toString(),
        );

        if ($user->status === UserStatus::Suspended) {
            return response()->json([
                'message' => 'This account has been suspended. Contact support.',
            ], 403);
        }

        $issued = $tokens->issue($user, $request, $request->string('device_name')->toString() ?: null);

        return response()->json([
            'message' => 'Signed in successfully.',
            'data' => [
                'user' => new UserResource($user->load('merchant')),
                ...$issued,
            ],
        ]);
    }

    /**
     * POST /api/v1/auth/refresh
     */
    public function refresh(Request $request, TokenService $tokens): JsonResponse
    {
        $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        $result = $tokens->refresh($request->string('refresh_token')->toString(), $request);

        return response()->json([
            'message' => 'Session refreshed.',
            'data' => [
                'user' => new UserResource($result['user']),
                ...$result['tokens'],
            ],
        ]);
    }

    /**
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()->load('merchant')),
        ]);
    }

    /**
     * POST /api/v1/auth/logout — this device only.
     */
    public function logout(Request $request, TokenService $tokens): JsonResponse
    {
        $tokens->revokeCurrent($request->user(), $request->user()->currentAccessToken()?->id);

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * POST /api/v1/auth/logout-all — every device.
     */
    public function logoutAll(Request $request, TokenService $tokens): JsonResponse
    {
        $tokens->revokeAllForUser($request->user()->id);

        return response()->json(['message' => 'Signed out on all devices.']);
    }

    /**
     * GET /api/v1/auth/sessions
     */
    public function sessions(Request $request, TokenService $tokens): JsonResponse
    {
        return response()->json([
            'data' => $tokens->sessions($request->user()),
        ]);
    }

    /**
     * DELETE /api/v1/auth/sessions/{session}
     */
    public function revokeSession(Request $request, TokenService $tokens, int $session): JsonResponse
    {
        if (! $tokens->revokeSession($request->user(), $session)) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        return response()->json(['message' => 'Session signed out.']);
    }
}
