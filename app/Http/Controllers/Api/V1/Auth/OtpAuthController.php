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
     * Request a login code
     *
     * Sends a 6-digit code by SMS. Works for a new number too — the account is
     * created on first successful verification.
     *
     * Codes last 5 minutes. A number may request 5 codes per hour, with 60
     * seconds between requests.
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

        $otp->request(
            $phone,
            $request->string('intended_role', 'customer')->toString(),
            $request->ip(),
        );

        // Always 200. Whether a row was created is an implementation detail,
        // and a varying status code only complicates the client.
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
        ]);
    }

    /**
     * Verify the code and sign in
     *
     * Returns the user plus an access token (60 minutes) and a refresh token
     * (30 days). Five wrong attempts burn the code and a new one must be
     * requested.
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
     * Refresh the token pair
     *
     * Call this once after a 401, then retry the original request. Both tokens
     * are replaced; the old pair stops working immediately.
     *
     * Presenting a refresh token that was already exchanged is treated as
     * theft and signs the user out of every device.
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
     * Current signed-in user
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()->load('merchant')),
        ]);
    }

    /**
     * Sign out this device
     *
     * Revokes the access token used for this request and its refresh token.
     * Other devices stay signed in.
     */
    public function logout(Request $request, TokenService $tokens): JsonResponse
    {
        $tokens->revokeCurrent($request->user(), $request->user()->currentAccessToken()?->id);

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * Sign out every device
     */
    public function logoutAll(Request $request, TokenService $tokens): JsonResponse
    {
        $tokens->revokeAllForUser($request->user()->id);

        return response()->json(['message' => 'Signed out on all devices.']);
    }

    /**
     * List active sessions
     *
     * Every device currently signed in, for a "where am I signed in" screen.
     */
    public function sessions(Request $request, TokenService $tokens): JsonResponse
    {
        return response()->json([
            'data' => $tokens->sessions($request->user()),
        ]);
    }

    /**
     * Sign out one device
     */
    public function revokeSession(Request $request, TokenService $tokens, int $session): JsonResponse
    {
        if (! $tokens->revokeSession($request->user(), $session)) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        return response()->json(['message' => 'Session signed out.']);
    }
}
