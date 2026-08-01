<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class OtpAuthTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '9876543210';

    private const CODE = '123456';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sms.driver' => 'null',
            // The service only honours this outside production; it exists so
            // tests and the Flutter developer do not need a live SMS gateway.
            'otp.fixed_code' => self::CODE,
        ]);

        RateLimiter::clear('otp-request:127.0.0.1');
    }

    /**
     * Send an authenticated request.
     *
     * Sanctum's guard caches the resolved user for the lifetime of the
     * container, and the test container is shared across requests within one
     * test. Without forgetting the guard, a request made after a token was
     * revoked still sees the cached user and wrongly succeeds. Real traffic
     * gets a fresh container per request, so this is a test-only concern.
     */
    private function api(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function requestCode(string $phone = self::PHONE, array $payload = []): string
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone] + $payload)
            ->assertSuccessful();

        return self::CODE;
    }

    public function test_requesting_a_code_stores_it_hashed_never_in_plaintext(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE])
            ->assertSuccessful()
            ->assertJsonPath('data.identifier', self::PHONE);

        $otp = OtpCode::where('identifier', self::PHONE)->firstOrFail();

        // A database leak must not hand an attacker working login codes.
        $this->assertNotEmpty($otp->code_hash);
        $this->assertStringStartsWith('$2y$', $otp->code_hash);
        $this->assertDatabaseMissing('otp_codes', ['code_hash' => '123456']);
    }

    public function test_verifying_a_correct_code_creates_the_user_and_returns_tokens(): void
    {
        $code = $this->requestCode();

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => self::PHONE,
            'code' => $code,
            'device_name' => 'pixel-8',
        ])->assertOk()
            ->assertJsonStructure(['data' => ['user', 'access_token', 'refresh_token', 'token_type', 'expires_in']]);

        $this->assertSame('Bearer', $response->json('data.token_type'));
        $this->assertSame(3600, $response->json('data.expires_in'));

        $user = User::where('phone', self::PHONE)->firstOrFail();
        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertNotNull($user->phone_verified_at);
    }

    public function test_a_rider_signup_stays_pending_until_kyc(): void
    {
        $code = $this->requestCode(payload: ['intended_role' => 'rider']);

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::PHONE, 'code' => $code])
            ->assertOk();

        $user = User::where('phone', self::PHONE)->firstOrFail();
        $this->assertSame(UserRole::Rider, $user->role);
        // A rider must not be able to take deliveries before documents are checked.
        $this->assertSame(UserStatus::Pending, $user->status);
    }

    public function test_an_existing_user_signs_in_without_a_duplicate_account(): void
    {
        $existing = User::create([
            'name' => 'Karthik', 'phone' => self::PHONE, 'password' => 'x',
            'role' => UserRole::Merchant, 'status' => UserStatus::Active,
        ]);

        $code = $this->requestCode();
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::PHONE, 'code' => $code])->assertOk();

        $this->assertSame(1, User::where('phone', self::PHONE)->count());
        // Signing in by OTP must not quietly downgrade a merchant to a customer.
        $this->assertSame(UserRole::Merchant, $existing->fresh()->role);
    }

    public function test_a_wrong_code_is_rejected_and_counts_against_the_attempt_budget(): void
    {
        $this->requestCode();

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::PHONE, 'code' => '000000'])
            ->assertStatus(422);

        $this->assertSame(1, OtpCode::where('identifier', self::PHONE)->first()->attempts);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_generated_codes_are_random_when_no_fixed_code_is_set(): void
    {
        config(['otp.fixed_code' => null]);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE])->assertSuccessful();
        $first = OtpCode::latest('id')->first();

        OtpCode::query()->update(['created_at' => now()->subMinutes(5)]);
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '9123456789'])->assertSuccessful();
        $second = OtpCode::latest('id')->first();

        // Different hashes of different random codes; a constant would collide.
        $this->assertNotSame($first->code_hash, $second->code_hash);
        $this->assertFalse(Hash::check(self::CODE, $first->code_hash));
    }

    public function test_the_code_is_burned_after_too_many_wrong_attempts(): void
    {
        $code = $this->requestCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < config('otp.max_attempts'); $i++) {
            $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::PHONE, 'code' => $wrong])
                ->assertStatus(422);
        }

        // Even the right code must now fail — otherwise the attempt limit is
        // just a speed bump.
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::PHONE, 'code' => $code])
            ->assertStatus(422);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $code = $this->requestCode();

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::PHONE, 'code' => $code])->assertOk();
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::PHONE, 'code' => $code])->assertStatus(422);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $code = $this->requestCode();

        OtpCode::where('identifier', self::PHONE)->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::PHONE, 'code' => $code])
            ->assertStatus(422);
    }

    public function test_requesting_a_new_code_invalidates_the_previous_one(): void
    {
        $this->requestCode();
        $firstId = OtpCode::latest('id')->first()->id;

        // Skip past the resend cooldown.
        OtpCode::where('identifier', self::PHONE)->update(['created_at' => now()->subMinutes(5)]);
        $this->requestCode();

        // Two live codes for one number would double the guessing surface.
        $this->assertNotNull(OtpCode::find($firstId)->consumed_at);
        $this->assertNull(OtpCode::latest('id')->first()->consumed_at);
        $this->assertSame(1, OtpCode::where('identifier', self::PHONE)->whereNull('consumed_at')->count());
    }

    public function test_resending_too_soon_is_blocked(): void
    {
        $this->requestCode();

        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_hourly_limit_per_phone_number(): void
    {
        for ($i = 0; $i < config('otp.max_per_hour'); $i++) {
            $this->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE])->assertSuccessful();
            OtpCode::where('identifier', self::PHONE)->update(['created_at' => now()->subMinutes(2)]);
        }

        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE])
            ->assertStatus(422);
    }

    public function test_invalid_phone_numbers_are_rejected(): void
    {
        foreach (['12345', '1234567890', '98765432101', 'abcdefghij'] as $bad) {
            $this->postJson('/api/v1/auth/otp/request', ['phone' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrors('phone');
        }
    }

    public function test_a_merchant_role_cannot_be_self_assigned_by_otp(): void
    {
        // Otherwise anyone could mint themselves a merchant account.
        $this->postJson('/api/v1/auth/otp/request', [
            'phone' => self::PHONE,
            'intended_role' => 'merchant',
        ])->assertStatus(422)->assertJsonValidationErrors('intended_role');

        $this->postJson('/api/v1/auth/otp/request', [
            'phone' => self::PHONE,
            'intended_role' => 'admin',
        ])->assertStatus(422);
    }

    public function test_a_suspended_account_cannot_sign_in(): void
    {
        User::create([
            'name' => 'Blocked', 'phone' => self::PHONE, 'password' => 'x',
            'role' => UserRole::Customer, 'status' => UserStatus::Suspended,
        ]);

        $code = $this->requestCode();

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::PHONE, 'code' => $code])
            ->assertStatus(403);
    }

    public function test_access_token_works_and_refresh_rotates_the_pair(): void
    {
        $code = $this->requestCode();
        $signIn = $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::PHONE, 'code' => $code])->assertOk();

        $access = $signIn->json('data.access_token');
        $refresh = $signIn->json('data.refresh_token');

        $this->api($access)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.phone', self::PHONE);

        $refreshed = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertOk();

        $newAccess = $refreshed->json('data.access_token');
        $newRefresh = $refreshed->json('data.refresh_token');

        $this->assertNotSame($access, $newAccess);
        $this->assertNotSame($refresh, $newRefresh);

        $this->api($newAccess)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_the_old_access_token_dies_when_the_pair_is_rotated(): void
    {
        $code = $this->requestCode();
        $signIn = $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::PHONE, 'code' => $code])->assertOk();

        $oldAccess = $signIn->json('data.access_token');
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $signIn->json('data.refresh_token')])->assertOk();

        $this->api($oldAccess)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_reusing_a_rotated_refresh_token_signs_out_every_session(): void
    {
        $code = $this->requestCode();
        $signIn = $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::PHONE, 'code' => $code])->assertOk();

        $stolen = $signIn->json('data.refresh_token');
        $legitimate = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $stolen])->assertOk();

        // The attacker replays the token the real user already exchanged.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $stolen])->assertStatus(422);

        // Both sides are locked out; the user simply signs in again.
        $this->api($legitimate->json('data.access_token'))
            ->getJson('/api/v1/auth/me')->assertStatus(401);

        $this->assertSame(
            0,
            RefreshToken::whereNull('revoked_at')->count(),
            'every refresh token in the chain should be revoked'
        );
    }

    public function test_an_unknown_refresh_token_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => str_repeat('a', 64)])
            ->assertStatus(422);
    }

    public function test_an_expired_refresh_token_is_rejected(): void
    {
        $code = $this->requestCode();
        $signIn = $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::PHONE, 'code' => $code])->assertOk();

        RefreshToken::query()->update(['expires_at' => now()->subDay()]);

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $signIn->json('data.refresh_token')])
            ->assertStatus(422);
    }

    public function test_logout_ends_only_the_current_device(): void
    {
        $codeA = $this->requestCode();
        $deviceA = $this->postJson('/api/v1/auth/otp/verify',
            ['phone' => self::PHONE, 'code' => $codeA, 'device_name' => 'phone'])->assertOk();

        OtpCode::where('identifier', self::PHONE)->update(['created_at' => now()->subMinutes(5)]);
        $codeB = $this->requestCode();
        $deviceB = $this->postJson('/api/v1/auth/otp/verify',
            ['phone' => self::PHONE, 'code' => $codeB, 'device_name' => 'tablet'])->assertOk();

        $this->api($deviceA->json('data.access_token'))
            ->postJson('/api/v1/auth/logout')->assertOk();

        $this->api($deviceA->json('data.access_token'))->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->api($deviceB->json('data.access_token'))->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_logout_all_ends_every_device(): void
    {
        $codeA = $this->requestCode();
        $deviceA = $this->postJson('/api/v1/auth/otp/verify',
            ['phone' => self::PHONE, 'code' => $codeA, 'device_name' => 'phone'])->assertOk();

        OtpCode::where('identifier', self::PHONE)->update(['created_at' => now()->subMinutes(5)]);
        $codeB = $this->requestCode();
        $deviceB = $this->postJson('/api/v1/auth/otp/verify',
            ['phone' => self::PHONE, 'code' => $codeB, 'device_name' => 'tablet'])->assertOk();

        $this->api($deviceA->json('data.access_token'))
            ->postJson('/api/v1/auth/logout-all')->assertOk();

        $this->api($deviceA->json('data.access_token'))->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->api($deviceB->json('data.access_token'))->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_sessions_can_be_listed_and_revoked_individually(): void
    {
        $codeA = $this->requestCode();
        $deviceA = $this->postJson('/api/v1/auth/otp/verify',
            ['phone' => self::PHONE, 'code' => $codeA, 'device_name' => 'phone'])->assertOk();

        OtpCode::where('identifier', self::PHONE)->update(['created_at' => now()->subMinutes(5)]);
        $codeB = $this->requestCode();
        $deviceB = $this->postJson('/api/v1/auth/otp/verify',
            ['phone' => self::PHONE, 'code' => $codeB, 'device_name' => 'tablet'])->assertOk();

        $sessions = $this->api($deviceA->json('data.access_token'))
            ->getJson('/api/v1/auth/sessions')->assertOk()->json('data');

        $this->assertCount(2, $sessions);
        $this->assertEqualsCanonicalizing(['phone', 'tablet'], array_column($sessions, 'device_name'));

        $tabletId = collect($sessions)->firstWhere('device_name', 'tablet')['id'];

        $this->api($deviceA->json('data.access_token'))
            ->deleteJson("/api/v1/auth/sessions/{$tabletId}")->assertOk();

        $this->api($deviceB->json('data.access_token'))->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->api($deviceA->json('data.access_token'))->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_a_user_cannot_revoke_another_users_session(): void
    {
        $codeA = $this->requestCode();
        $victim = $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::PHONE, 'code' => $codeA])->assertOk();
        $victimSessionId = RefreshToken::latest('id')->first()->id;

        $codeB = $this->requestCode('9123456789');
        $attacker = $this->postJson('/api/v1/auth/otp/verify',
            ['phone' => '9123456789', 'code' => $codeB])->assertOk();

        $this->api($attacker->json('data.access_token'))
            ->deleteJson("/api/v1/auth/sessions/{$victimSessionId}")
            ->assertStatus(404);

        $this->api($victim->json('data.access_token'))->getJson('/api/v1/auth/me')->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Email channel — used until DLT registration and an SMS gateway are ready
    |--------------------------------------------------------------------------
    */

    public function test_an_email_identifier_sends_the_code_by_email(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/otp/request', ['email' => 'Karthik@Example.IN'])
            ->assertSuccessful()
            ->assertJsonPath('data.channel', 'email')
            // Addresses are case-insensitive, so they are stored lowercased —
            // otherwise "Karthik@" and "karthik@" become two accounts.
            ->assertJsonPath('data.identifier', 'karthik@example.in');

        Mail::assertSent(OtpCodeMail::class, fn ($mail) => $mail->hasTo('karthik@example.in'));

        $otp = OtpCode::latest('id')->firstOrFail();
        $this->assertSame('email', $otp->channel);
        $this->assertSame('karthik@example.in', $otp->identifier);
    }

    public function test_a_phone_identifier_does_not_send_an_email(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE])->assertSuccessful()
            ->assertJsonPath('data.channel', 'sms');

        Mail::assertNothingSent();
    }

    public function test_email_signin_creates_the_account_and_marks_email_verified(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/otp/request', ['email' => 'meena@example.in'])->assertSuccessful();

        $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'meena@example.in',
            'code' => self::CODE,
            'device_name' => 'pixel-8',
        ])->assertOk()->assertJsonPath('data.user.email', 'meena@example.in');

        $user = User::where('email', 'meena@example.in')->firstOrFail();
        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertNotNull($user->email_verified_at);
        // The account has no mobile number yet; that comes when SMS is enabled.
        $this->assertNull($user->phone);
    }

    public function test_an_existing_email_account_is_reused_not_duplicated(): void
    {
        Mail::fake();

        User::create([
            'name' => 'Existing', 'email' => 'meena@example.in', 'password' => 'x',
            'role' => UserRole::Customer, 'status' => UserStatus::Active,
        ]);

        $this->postJson('/api/v1/auth/otp/request', ['email' => 'meena@example.in'])->assertSuccessful();
        $this->postJson('/api/v1/auth/otp/verify', ['email' => 'meena@example.in', 'code' => self::CODE])
            ->assertOk();

        $this->assertSame(1, User::where('email', 'meena@example.in')->count());
    }

    public function test_the_identifier_is_required_and_cannot_be_both(): void
    {
        $this->postJson('/api/v1/auth/otp/request', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'phone']);

        // Ambiguous: which channel would the code go to?
        $this->postJson('/api/v1/auth/otp/request', [
            'email' => 'a@b.in', 'phone' => self::PHONE,
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_a_malformed_email_is_rejected(): void
    {
        foreach (['not-an-email', 'a@', '@b.in', 'a b@c.in'] as $bad) {
            $this->postJson('/api/v1/auth/otp/request', ['email' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrors('email');
        }
    }

    public function test_email_codes_obey_the_same_limits_as_sms(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/otp/request', ['email' => 'meena@example.in'])->assertSuccessful();

        // Resend cooldown.
        $this->postJson('/api/v1/auth/otp/request', ['email' => 'meena@example.in'])
            ->assertStatus(422);

        // Wrong code still burns attempts.
        $this->postJson('/api/v1/auth/otp/verify', ['email' => 'meena@example.in', 'code' => '000000'])
            ->assertStatus(422);
        $this->assertSame(1, OtpCode::where('identifier', 'meena@example.in')->first()->attempts);
    }

    public function test_protected_endpoints_reject_anonymous_callers(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->postJson('/api/v1/auth/logout')->assertStatus(401);
        $this->getJson('/api/v1/auth/sessions')->assertStatus(401);
    }
}
