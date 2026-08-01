<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public function __construct(private readonly OtpDelivery $delivery) {}

    /**
     * Issue a code and deliver it.
     *
     * $identifier is a 10-digit mobile number or an email address; the channel
     * follows from which it is.
     *
     * @throws ValidationException when throttled
     */
    public function request(string $identifier, string $intendedRole = 'customer', ?string $ip = null): OtpCode
    {
        $identifier = $this->normalise($identifier);

        $this->guardResendCooldown($identifier);
        $this->guardHourlyLimit($identifier);

        $channel = OtpDelivery::channelFor($identifier);
        $code = $this->generateCode();

        /*
         * Any earlier code is burned. Without this, a user who requests twice
         * would have two valid codes, doubling the guessing surface and making
         * "which code is current" ambiguous.
         */
        OtpCode::where('identifier', $identifier)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $otp = OtpCode::create([
            'identifier' => $identifier,
            'channel' => $channel,
            'code_hash' => Hash::make($code),
            'purpose' => 'login',
            'intended_role' => $intendedRole,
            'expires_at' => now()->addSeconds(config('otp.ttl_seconds')),
            'ip_address' => $ip,
        ]);

        $this->delivery->send($identifier, $channel, $code);

        return $otp;
    }

    /**
     * Check a code and return the user it belongs to, creating the account on
     * first login.
     *
     * @throws ValidationException when the code is wrong, expired or exhausted
     */
    public function verify(string $identifier, string $code): User
    {
        $identifier = $this->normalise($identifier);

        $otp = OtpCode::where('identifier', $identifier)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $otp || ! $otp->isUsable()) {
            $this->fail('This code has expired. Please request a new one.');
        }

        $otp->increment('attempts');

        if (! Hash::check($code, $otp->code_hash)) {
            // Burn the code once the attempt budget is gone, so an attacker
            // cannot keep guessing against the same code.
            if ($otp->attempts >= config('otp.max_attempts')) {
                $otp->update(['consumed_at' => now()]);
                $this->fail('Too many incorrect attempts. Please request a new code.');
            }

            $remaining = config('otp.max_attempts') - $otp->attempts;
            $this->fail("That code is not correct. {$remaining} attempts remaining.");
        }

        $otp->update(['consumed_at' => now()]);

        return $this->resolveUser($identifier, $otp->channel, $otp->intended_role);
    }

    /** Email addresses are case-insensitive; mobile numbers are left alone. */
    private function normalise(string $identifier): string
    {
        $identifier = trim($identifier);

        return filter_var($identifier, FILTER_VALIDATE_EMAIL)
            ? mb_strtolower($identifier)
            : $identifier;
    }

    /**
     * A fixed code for QA and for the app developers while no gateway exists.
     * Hard-blocked outside local and testing so it can never become a master
     * key in production, however the environment is configured.
     */
    private function generateCode(): string
    {
        $fixed = config('otp.fixed_code');

        if ($fixed && app()->environment(['local', 'testing'])) {
            return (string) $fixed;
        }

        $length = config('otp.length');

        // random_int is cryptographically secure; rand() and mt_rand() are not.
        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    private function resolveUser(string $identifier, string $channel, string $intendedRole): User
    {
        $column = $channel === OtpDelivery::EMAIL ? 'email' : 'phone';
        $verifiedColumn = $channel === OtpDelivery::EMAIL ? 'email_verified_at' : 'phone_verified_at';

        $user = User::where($column, $identifier)->first();

        if ($user) {
            $user->forceFill([$verifiedColumn => now()]);

            // A customer who verified their contact has nothing left to check,
            // so activate them. Riders and merchants stay pending until KYC.
            if ($user->status === UserStatus::Pending && $user->role === UserRole::Customer) {
                $user->forceFill(['status' => UserStatus::Active]);
            }

            $user->save();

            return $user;
        }

        $role = UserRole::tryFrom($intendedRole) ?? UserRole::Customer;

        $user = User::create([
            'name' => 'Nexmile user',
            $column => $identifier,
            'password' => Hash::make(str()->random(40)),
            'role' => $role,
            // Riders must complete KYC before they can work; customers are
            // ready to order immediately.
            'status' => $role === UserRole::Customer ? UserStatus::Active : UserStatus::Pending,
        ]);

        // Deliberately not mass-assignable: verification status must never be
        // settable from request input.
        $user->forceFill([$verifiedColumn => now()])->save();

        return $user;
    }

    private function guardResendCooldown(string $identifier): void
    {
        $cooldown = (int) config('otp.resend_cooldown_seconds');

        $recent = OtpCode::where('identifier', $identifier)
            ->where('created_at', '>=', now()->subSeconds($cooldown))
            ->latest('id')
            ->first();

        if ($recent) {
            $wait = max(1, $cooldown - $recent->created_at->diffInSeconds(now()));
            $this->fail("Please wait {$wait} seconds before requesting another code.");
        }
    }

    private function guardHourlyLimit(string $identifier): void
    {
        $count = OtpCode::where('identifier', $identifier)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($count >= (int) config('otp.max_per_hour')) {
            $this->fail('Too many codes requested. Please try again later.');
        }
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['code' => $message]);
    }
}
