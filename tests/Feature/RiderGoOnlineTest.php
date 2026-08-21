<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\RiderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Rider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Going on duty.
 *
 * A verified rider who cannot go online is not a small bug: they cannot earn,
 * and from the admin panel everything looks approved, so nobody can see why.
 */
class RiderGoOnlineTest extends TestCase
{
    use RefreshDatabase;

    private function rider(array $attributes = []): Rider
    {
        $user = User::create([
            'name' => 'Rider',
            'phone' => '9791000001',
            'email' => 'rider@example.in',
            'password' => 'secret',
            'role' => UserRole::Rider,
            'status' => UserStatus::Active,
        ]);

        return $user->rider()->create([
            'full_name' => 'Selvam K',
            'vehicle_type' => 'motorcycle',
            'kyc_status' => KycStatus::Verified,
            'kyc_verified_at' => now(),
            'driving_licence_expiry' => now()->addYear(),
            'insurance_expiry' => now()->addYear(),
            // Offline, which is where every rider starts the day.
            'duty_status' => RiderStatus::Offline,
            ...$attributes,
        ]);
    }

    public function test_an_offline_verified_rider_is_told_they_may_go_online(): void
    {
        /*
         * The regression this file exists for. can_accept_orders also requires
         * being on duty, so it is false for every offline rider — an app gating
         * the Go online button on it locks the rider out permanently, which is
         * exactly what happened. can_go_online answers the question actually
         * being asked.
         */
        $rider = $this->rider();
        Sanctum::actingAs($rider->user);

        $this->getJson('/api/v1/rider/profile')
            ->assertOk()
            ->assertJsonPath('data.can_go_online', true)
            ->assertJsonPath('data.offline_reason', null)
            ->assertJsonPath('data.can_accept_orders', false);
    }

    public function test_a_verified_rider_can_actually_go_online(): void
    {
        $rider = $this->rider();
        Sanctum::actingAs($rider->user);

        $this->postJson('/api/v1/rider/duty-status', ['duty_status' => 'available'])
            ->assertOk();

        $this->assertSame(RiderStatus::Available, $rider->fresh()->duty_status);
    }

    public function test_an_unverified_rider_is_refused_and_told_why(): void
    {
        $rider = $this->rider(['kyc_status' => KycStatus::Pending, 'kyc_verified_at' => null]);
        Sanctum::actingAs($rider->user);

        $this->getJson('/api/v1/rider/profile')
            ->assertOk()
            ->assertJsonPath('data.can_go_online', false)
            ->assertJsonPath('data.offline_reason', 'Your documents are still being verified.');

        $this->postJson('/api/v1/rider/duty-status', ['duty_status' => 'available'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Your documents are still being verified.');
    }

    /**
     * The second trap, and the harder one to spot: pressing Verify in the admin
     * panel does not set these dates, so an approved rider with a blank licence
     * expiry is still blocked — and the admin has no way to see it.
     */
    public function test_a_verified_rider_with_no_expiry_dates_is_blocked_for_the_right_reason(): void
    {
        $rider = $this->rider(['driving_licence_expiry' => null, 'insurance_expiry' => null]);
        Sanctum::actingAs($rider->user);

        $this->getJson('/api/v1/rider/profile')
            ->assertOk()
            ->assertJsonPath('data.can_go_online', false)
            ->assertJsonPath('data.kyc.documents_expired', true)
            ->assertJsonPath(
                'data.offline_reason',
                'Your licence or insurance has expired. Upload current documents to go online.',
            );
    }

    public function test_the_refusal_wording_is_the_same_everywhere(): void
    {
        // The profile flag and the duty-status refusal come from one method, so
        // the app cannot be shown one reason and told another.
        $rider = $this->rider(['insurance_expiry' => now()->subDay()]);
        Sanctum::actingAs($rider->user);

        $shown = $this->getJson('/api/v1/rider/profile')->json('data.offline_reason');
        $refused = $this->postJson('/api/v1/rider/duty-status', ['duty_status' => 'available'])
            ->assertStatus(403)->json('message');

        $this->assertSame($shown, $refused);
    }
}
