<?php

namespace App\Services\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Access and refresh token handling for every role.
 *
 * Access tokens are Sanctum personal access tokens with a one-hour expiry, so
 * a leaked token is useful only briefly. The refresh token lets the app stay
 * signed in for weeks without asking for another OTP.
 */
class TokenService
{
    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function issue(User $user, ?Request $request = null, ?string $deviceName = null): array
    {
        $deviceName = $deviceName ?: 'mobile-app';
        $ttlMinutes = (int) config('otp.access_token_ttl_minutes');

        $accessToken = $user->createToken(
            $deviceName,
            ['*'],
            now()->addMinutes($ttlMinutes)
        );

        $plainRefresh = $this->newRefreshTokenString();

        RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainRefresh),
            'access_token_id' => $accessToken->accessToken->id,
            'device_name' => $deviceName,
            'ip_address' => $request?->ip(),
            'user_agent' => substr((string) $request?->userAgent(), 0, 255) ?: null,
            'expires_at' => now()->addDays((int) config('otp.refresh_token_ttl_days')),
        ]);

        return [
            'access_token' => $accessToken->plainTextToken,
            'refresh_token' => $plainRefresh,
            'token_type' => 'Bearer',
            'expires_in' => $ttlMinutes * 60,
        ];
    }

    /**
     * Exchange a refresh token for a new pair, rotating the old one.
     *
     * @return array{user: User, tokens: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public function refresh(string $plainRefresh, ?Request $request = null): array
    {
        $hash = hash('sha256', $plainRefresh);

        /** @var RefreshToken|null $token */
        $token = RefreshToken::where('token_hash', $hash)->first();

        if (! $token) {
            $this->fail('This session is no longer valid. Please sign in again.');
        }

        /*
         * Reuse detection. A rotated token should never be presented again —
         * if it is, either it was stolen or an attacker is replaying it. There
         * is no way to tell which, so every session in the chain is dropped and
         * the user signs in again.
         */
        if ($token->wasRotated()) {
            $this->revokeAllForUser($token->user_id);
            $this->fail('This session was already used. All sessions have been signed out for your security.');
        }

        if (! $token->isActive()) {
            $this->fail('This session has expired. Please sign in again.');
        }

        $user = $token->user;

        if (! $user) {
            $this->fail('This session is no longer valid. Please sign in again.');
        }

        return DB::transaction(function () use ($token, $user, $request) {
            // The access token issued alongside the old refresh token dies with it.
            if ($token->access_token_id) {
                $user->tokens()->whereKey($token->access_token_id)->delete();
            }

            $tokens = $this->issue($user, $request, $token->device_name);

            $replacement = RefreshToken::where('user_id', $user->id)
                ->latest('id')
                ->first();

            $token->update([
                'revoked_at' => now(),
                'last_used_at' => now(),
                'replaced_by_id' => $replacement?->id,
            ]);

            return ['user' => $user->fresh(), 'tokens' => $tokens];
        });
    }

    /** Sign out the current device only. */
    public function revokeCurrent(User $user, ?int $accessTokenId): void
    {
        if ($accessTokenId === null) {
            return;
        }

        RefreshToken::where('user_id', $user->id)
            ->where('access_token_id', $accessTokenId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $user->tokens()->whereKey($accessTokenId)->delete();
    }

    /** Sign out everywhere — used on "log out all devices" and on token reuse. */
    public function revokeAllForUser(int $userId): void
    {
        RefreshToken::where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        User::find($userId)?->tokens()->delete();
    }

    /**
     * Active sessions, for a "where am I signed in" screen.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function sessions(User $user)
    {
        return RefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->get()
            ->map(fn (RefreshToken $t) => [
                'id' => $t->id,
                'device_name' => $t->device_name,
                'ip_address' => $t->ip_address,
                'last_used_at' => $t->last_used_at,
                'created_at' => $t->created_at,
                'expires_at' => $t->expires_at,
            ]);
    }

    public function revokeSession(User $user, int $sessionId): bool
    {
        /** @var RefreshToken|null $token */
        $token = RefreshToken::where('user_id', $user->id)->find($sessionId);

        if (! $token || ! $token->isActive()) {
            return false;
        }

        if ($token->access_token_id) {
            $user->tokens()->whereKey($token->access_token_id)->delete();
        }

        $token->update(['revoked_at' => now()]);

        return true;
    }

    private function newRefreshTokenString(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['refresh_token' => $message]);
    }
}
