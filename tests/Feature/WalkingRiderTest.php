<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\RiderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Rider;
use App\Models\User;
use App\Services\Kyc\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Delivering on foot.
 *
 * Inside a kilometre walking is a real way to work, and the people who need
 * that option are often the ones without a licence. Adding the vehicle type
 * while still demanding a driving licence, an RC and vehicle insurance would
 * have left them permanently stuck in onboarding with nothing to upload.
 */
class WalkingRiderTest extends TestCase
{
    use RefreshDatabase;

    private function rider(string $vehicle, array $attributes = []): Rider
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'name' => 'Rider '.$n,
            'phone' => '92000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "walk{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Rider,
            'status' => UserStatus::Active,
        ]);

        return $user->rider()->create([
            'full_name' => 'Selvam K',
            'vehicle_type' => $vehicle,
            'kyc_status' => KycStatus::Verified,
            'kyc_verified_at' => now(),
            'duty_status' => RiderStatus::Offline,
            ...$attributes,
        ]);
    }

    public function test_a_rider_can_choose_to_walk(): void
    {
        $rider = $this->rider('motorcycle', [
            'driving_licence_expiry' => now()->addYear(),
            'insurance_expiry' => now()->addYear(),
        ]);

        Sanctum::actingAs($rider->user);

        $this->patchJson('/api/v1/rider/profile', ['vehicle_type' => 'walk'])
            ->assertOk()
            ->assertJsonPath('data.vehicle.type', 'walk');
    }

    public function test_an_invented_vehicle_type_is_refused(): void
    {
        $rider = $this->rider('walk');

        Sanctum::actingAs($rider->user);

        $this->patchJson('/api/v1/rider/profile', ['vehicle_type' => 'helicopter'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('vehicle_type');
    }

    public function test_someone_on_foot_can_go_online_without_a_licence(): void
    {
        /*
         * The whole point. A walking rider has no licence and no insurance, so
         * the expiry gate — which treats a blank date as expired — would have
         * barred them for good from work they are perfectly able to do.
         */
        $rider = $this->rider('walk', [
            'driving_licence_expiry' => null,
            'insurance_expiry' => null,
        ]);

        $this->assertFalse($rider->hasExpiredDocuments());
        $this->assertTrue($rider->canGoOnline());
        $this->assertNull($rider->offlineReason());

        Sanctum::actingAs($rider->user);

        $this->postJson('/api/v1/rider/duty-status', ['duty_status' => 'available'])
            ->assertOk();

        $this->assertSame(RiderStatus::Available, $rider->fresh()->duty_status);
    }

    public function test_a_cyclist_is_treated_the_same_way(): void
    {
        // A bicycle needs no licence, registration or insurance either.
        $rider = $this->rider('bicycle', [
            'driving_licence_expiry' => null,
            'insurance_expiry' => null,
        ]);

        $this->assertFalse($rider->isMotorised());
        $this->assertTrue($rider->canGoOnline());
    }

    public function test_a_motorcycle_still_needs_its_paperwork(): void
    {
        // The exemption must not become a way around the rule that matters:
        // dispatching someone with a lapsed licence is a legal exposure.
        $rider = $this->rider('motorcycle', [
            'driving_licence_expiry' => null,
            'insurance_expiry' => null,
        ]);

        $this->assertTrue($rider->isMotorised());
        $this->assertTrue($rider->hasExpiredDocuments());
        $this->assertFalse($rider->canGoOnline());
    }

    public function test_a_walking_rider_is_not_asked_for_a_licence_or_an_rc(): void
    {
        // Otherwise onboarding shows them documents that do not exist and they
        // cannot submit, with no way to explain why.
        $rider = $this->rider('walk');

        $missing = app(DocumentService::class)->missingDocuments($rider, 'rider');

        $this->assertNotContains('driving_licence', $missing);
        $this->assertNotContains('vehicle_rc', $missing);
        $this->assertNotContains('vehicle_insurance', $missing);

        // Identity is still required — that is who they are, not what they ride.
        $this->assertContains('aadhaar_front', $missing);
        $this->assertContains('pan_card', $missing);
    }

    public function test_a_motorcyclist_is_still_asked_for_all_of_it(): void
    {
        $rider = $this->rider('motorcycle');

        $missing = app(DocumentService::class)->missingDocuments($rider, 'rider');

        $this->assertContains('driving_licence', $missing);
        $this->assertContains('vehicle_rc', $missing);
        $this->assertContains('vehicle_insurance', $missing);
    }

    public function test_switching_to_a_motorcycle_reinstates_the_requirements(): void
    {
        /*
         * A rider who starts on foot and later buys a scooter must be asked
         * for the paperwork then. The exemption follows the vehicle, not the
         * account.
         */
        $rider = $this->rider('walk');

        $this->assertFalse($rider->hasExpiredDocuments());

        $rider->update(['vehicle_type' => 'scooter']);

        $this->assertTrue($rider->fresh()->hasExpiredDocuments());
        $this->assertContains(
            'driving_licence',
            app(DocumentService::class)->missingDocuments($rider->fresh(), 'rider'),
        );
    }
}
