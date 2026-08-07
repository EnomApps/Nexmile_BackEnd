<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RestaurantDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    /** Madurai Periyar bus stand, near enough. */
    private const LAT = 9.9195;

    private const LNG = 78.1193;

    private function user(UserRole $role = UserRole::Customer): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'User '.$n,
            'phone' => '97650000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "discover{$n}@example.in",
            'password' => 'secret',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function restaurant(array $attributes = []): Merchant
    {
        static $n = 0;
        $n++;

        return Merchant::create([
            'user_id' => $this->user(UserRole::Merchant)->id,
            'business_name' => 'Restaurant '.$n,
            'owner_name' => 'Owner',
            'address_line1' => '1 Main Road',
            'city' => 'Madurai',
            'pincode' => '625001',
            'latitude' => self::LAT,
            'longitude' => self::LNG,
            'kyc_status' => KycStatus::Verified,
            'is_accepting_orders' => true,
            'fssai_license_no' => '12345678901234',
            'fssai_expiry_date' => now()->addYear(),
            ...$attributes,
        ]);
    }

    /**
     * Roughly how far north $metres puts you, in degrees of latitude.
     */
    private function latOffset(int $metres): float
    {
        return rad2deg($metres / 6371000);
    }

    public function test_it_returns_restaurants_inside_the_radius_and_excludes_those_outside(): void
    {
        Sanctum::actingAs($this->user());

        $near = $this->restaurant(['business_name' => 'Ponnusamy']);
        $this->restaurant([
            'business_name' => 'Too Far',
            'latitude' => self::LAT + $this->latOffset(2500),
        ]);

        $response = $this->getJson('/api/v1/restaurants?latitude='.self::LAT.'&longitude='.self::LNG)
            ->assertOk()
            ->assertJsonPath('meta.radius_metres', 1000);

        $this->assertSame([$near->id], array_column($response->json('data'), 'id'));
    }

    public function test_results_are_ordered_by_open_first_then_distance(): void
    {
        Sanctum::actingAs($this->user());

        $closedButClose = $this->restaurant(['business_name' => 'Closed', 'is_accepting_orders' => false]);
        $openButFurther = $this->restaurant([
            'business_name' => 'Open',
            'latitude' => self::LAT + $this->latOffset(400),
        ]);

        $ids = array_column(
            $this->getJson('/api/v1/restaurants?latitude='.self::LAT.'&longitude='.self::LNG)->json('data'),
            'id',
        );

        // A closer kitchen that cannot cook is worth less than one that can.
        $this->assertSame([$openButFurther->id, $closedButClose->id], $ids);
    }

    public function test_unverified_restaurants_are_never_listed_or_addressable(): void
    {
        Sanctum::actingAs($this->user());

        $pending = $this->restaurant(['kyc_status' => KycStatus::Pending]);

        $this->getJson('/api/v1/restaurants?latitude='.self::LAT.'&longitude='.self::LNG)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Nobody has confirmed a licensed food business is behind it.
        $this->getJson("/api/v1/restaurants/{$pending->id}")->assertNotFound();
        $this->getJson("/api/v1/restaurants/{$pending->id}/menu")->assertNotFound();
    }

    public function test_the_public_shape_hides_merchant_and_admin_fields(): void
    {
        Sanctum::actingAs($this->user());

        $shop = $this->restaurant([
            'bank_account_number' => '123456789012',
            'bank_ifsc' => 'SBIN0001234',
            'commission_rate' => 18.5,
            'gstin' => '33AAAAA0000A1Z5',
        ]);

        $body = $this->getJson("/api/v1/restaurants/{$shop->id}")->assertOk()->json('data');

        foreach (['bank_account_number', 'bank_ifsc', 'commission_rate', 'gstin', 'owner_name', 'kyc'] as $leak) {
            $this->assertArrayNotHasKey($leak, $body, "{$leak} must not reach a customer");
        }
    }

    public function test_an_address_id_belonging_to_someone_else_is_not_a_window_onto_where_they_live(): void
    {
        $victim = $this->user();
        $address = $victim->addresses()->create([
            'label' => 'home', 'line1' => '5 Secret Street', 'city' => 'Madurai',
            'pincode' => '625001', 'latitude' => self::LAT, 'longitude' => self::LNG,
        ]);

        Sanctum::actingAs($this->user());

        $this->getJson('/api/v1/restaurants?address_id='.$address->id)->assertNotFound();
    }

    public function test_a_rider_can_browse_restaurants_like_any_other_customer(): void
    {
        // Ordering is not a role — docs/ROLES.md.
        Sanctum::actingAs($this->user(UserRole::Rider));
        $this->restaurant();

        $this->getJson('/api/v1/restaurants?latitude='.self::LAT.'&longitude='.self::LNG)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_discovery_requires_a_location(): void
    {
        Sanctum::actingAs($this->user());

        $this->getJson('/api/v1/restaurants')
            ->assertStatus(422)
            ->assertJsonValidationErrors('address_id');
    }

    public function test_discovery_rejects_anonymous_callers(): void
    {
        $this->getJson('/api/v1/restaurants?latitude='.self::LAT.'&longitude='.self::LNG)
            ->assertUnauthorized();
    }
}
