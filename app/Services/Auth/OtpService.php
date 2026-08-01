<?php

namespace App\Services\Auth;

use App\Contracts\SmsSender;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public function __construct(private readonly SmsSender $sms) {}

    /**
     * Issue a code and send it.
     *
     * @throws ValidationException when throttled
     */
    public function request(string $phone, string $intendedRole = 'customer', ?string $ip = null): OtpCode
    {
        $this->guardResendCooldown($phone);
        $this->guardHourlyLimit($phone);

        $code = $this->generateCode();

        /*
         * Any earlier code is burned. Without this, a user who requests twice
         * would have two valid codes, doubling the guessing surface and making
         * "which code is current" ambiguous.
         */
        OtpCode::where('phone', $phone)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $otp = OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'purpose' => 'login',
            'intended_role' => $intendedRole,
            'expires_at' => now()->addSeconds(config('otp.ttl_seconds')),
            'ip_address' => $ip,
        ]);

        $minutes = (int) ceil(config('otp.ttl_seconds') / 60);
        $this->sms->send($phone, "{$code} is your Nexmile verification code. It expires in {$minutes} minutes. Do not share it with anyone.");

        return $otp;
    }

    /**
     * Check a code and return the user it belongs to, creating the account on
     * first login.
     *
     * @throws ValidationException when the code is wrong, expired or exhausted
     */
    public function verify(string $phone, string $code): User
    {
        $otp = OtpCode::where('phone', $phone)
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

        return $this->resolveUser($phone, $otp->intended_role);
    }

    /**
     * A fixed code for QA and for the Flutter developer while no gateway
     * exists. Hard-blocked outside local and testing so it can never become a
     * master key in production, however the environment is configured.
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

    private function resolveUser(string $phone, string $intendedRole): User
    {
        $user = User::where('phone', $phone)->first();

        if ($user) {
            $user->forceFill(['phone_verified_at' => now()]);

            // A customer who verified their number has nothing left to check,
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
            'phone' => $phone,
            'password' => Hash::make(str()->random(40)),
            'role' => $role,
            // Riders must complete KYC before they can work; customers are
            // ready to order immediately.
            'status' => $role === UserRole::Customer ? UserStatus::Active : UserStatus::Pending,
        ]);

        // Deliberately not mass-assignable: verification status must never be
        // settable from request input.
        $user->forceFill(['phone_verified_at' => now()])->save();

        return $user;
    }

    private function guardResendCooldown(string $phone): void
    {
        $cooldown = (int) config('otp.resend_cooldown_seconds');

        $recent = OtpCode::where('phone', $phone)
            ->where('created_at', '>=', now()->subSeconds($cooldown))
            ->latest('id')
            ->first();

        if ($recent) {
            $wait = max(1, $cooldown - $recent->created_at->diffInSeconds(now()));
            $this->fail("Please wait {$wait} seconds before requesting another code.");
        }
    }

    private function guardHourlyLimit(string $phone): void
    {
        $count = OtpCode::where('phone', $phone)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($count >= (int) config('otp.max_per_hour')) {
            $this->fail('Too many codes requested for this number. Please try again later.');
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
