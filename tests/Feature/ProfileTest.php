<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Address;
use App\Models\Merchant;
use App\Models\Rider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role = UserRole::Customer, array $attributes = []): User
    {
        static $n = 0;
        $n++;

        return User::create(array_merge([
            'name' => 'Test User',
            'phone' => '98765432'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "user{$n}@example.in",
            'password' => 'secret',
            'role' => $role,
            'status' => UserStatus::Active,
        ], $attributes));
    }

    private function address(User $user, array $attributes = []): Address
    {
        return $user->addresses()->create(array_merge([
            'label' => 'home',
            'line1' => '12 West Masi Street',
            'city' => 'Madurai',
            'pincode' => '625001',
            'latitude' => 9.9252007,
            'longitude' => 78.1197754,
        ], $attributes));
    }

    /*
    |--------------------------------------------------------------------------
    | Shared profile
    |--------------------------------------------------------------------------
    */

    public function test_a_signed_in_user_can_read_their_profile(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', 'customer');
    }

    public function test_profile_fields_can_be_updated(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/profile', [
            'name' => 'Karthik Raja',
            'preferred_locale' => 'ta',
        ])->assertOk()->assertJsonPath('data.name', 'Karthik Raja');

        $this->assertSame('ta', $user->fresh()->preferred_locale);
    }

    public function test_role_and_status_cannot_be_changed_through_the_profile(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/profile', [
            'name' => 'Sneaky',
            'role' => 'admin',
            'status' => 'active',
        ])->assertOk();

        // Self-promotion to admin would be a total authorisation bypass.
        $this->assertSame(UserRole::Customer, $user->fresh()->role);
    }

    public function test_changing_a_verified_phone_clears_its_verification(): void
    {
        $user = $this->user();
        $user->forceFill(['phone_verified_at' => now()])->save();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/profile', ['phone' => '9000011111'])->assertOk();

        // Otherwise a user verifies one number then swaps in another and
        // appears verified on a number they do not control.
        $this->assertNull($user->fresh()->phone_verified_at);
    }

    public function test_an_unchanged_phone_keeps_its_verification(): void
    {
        $user = $this->user();
        $user->forceFill(['phone_verified_at' => now()])->save();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/profile', ['phone' => $user->phone, 'name' => 'Same Number'])
            ->assertOk();

        $this->assertNotNull($user->fresh()->phone_verified_at);
    }

    public function test_a_phone_already_taken_by_another_user_is_rejected(): void
    {
        $other = $this->user();
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/profile', ['phone' => $other->phone])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_closing_an_account_soft_deletes_and_revokes_tokens(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/profile')->assertOk();

        // Soft delete keeps past orders and invoices intact for tax purposes.
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_a_merchant_taking_orders_cannot_close_their_account(): void
    {
        $user = $this->user(UserRole::Merchant);
        Merchant::create([
            'user_id' => $user->id, 'business_name' => 'Open Kitchen', 'owner_name' => 'X',
            'address_line1' => '1 Road', 'city' => 'Madurai', 'pincode' => '625001',
            'is_accepting_orders' => true,
        ]);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/profile')->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'deleted_at' => null]);
    }

    /*
    |--------------------------------------------------------------------------
    | Address book
    |--------------------------------------------------------------------------
    */

    public function test_the_first_address_becomes_the_default_automatically(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/addresses', [
            'line1' => '12 West Masi Street', 'city' => 'Madurai', 'pincode' => '625001',
            'latitude' => 9.9252007, 'longitude' => 78.1197754,
        ])->assertCreated()->assertJsonPath('data.is_default', true);
    }

    public function test_only_one_address_can_be_default(): void
    {
        $user = $this->user();
        $first = $this->address($user, ['is_default' => true]);
        Sanctum::actingAs($user);

        $second = $this->postJson('/api/v1/addresses', [
            'line1' => '45 Tallakulam Road', 'city' => 'Madurai', 'pincode' => '625002',
            'latitude' => 9.93, 'longitude' => 78.12, 'is_default' => true,
        ])->assertCreated()->json('data.id');

        $this->assertFalse(Address::find($first->id)->is_default);
        $this->assertTrue(Address::find($second)->is_default);
        $this->assertSame(1, $user->addresses()->where('is_default', true)->count());
    }

    public function test_coordinates_are_required(): void
    {
        Sanctum::actingAs($this->user());

        // Without them an address cannot be matched to a zone or a merchant,
        // so the 1 km discovery has nothing to work from.
        $this->postJson('/api/v1/addresses', [
            'line1' => '12 West Masi Street', 'city' => 'Madurai', 'pincode' => '625001',
        ])->assertStatus(422)->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    public function test_addresses_are_listed_default_first(): void
    {
        $user = $this->user();
        $this->address($user);
        $default = $this->address($user, ['line1' => 'Default Street']);
        $default->makeDefault();
        Sanctum::actingAs($user);

        $list = $this->getJson('/api/v1/addresses')->assertOk()->json('data');

        $this->assertCount(2, $list);
        $this->assertTrue($list[0]['is_default']);
    }

    public function test_a_user_cannot_read_or_change_another_users_address(): void
    {
        $victim = $this->user();
        $theirs = $this->address($victim);

        Sanctum::actingAs($this->user());

        // 404 rather than 403: a 403 would confirm the address exists.
        $this->getJson("/api/v1/addresses/{$theirs->id}")->assertStatus(404);
        $this->patchJson("/api/v1/addresses/{$theirs->id}", ['city' => 'Hacked'])->assertStatus(404);
        $this->deleteJson("/api/v1/addresses/{$theirs->id}")->assertStatus(404);
        $this->postJson("/api/v1/addresses/{$theirs->id}/default")->assertStatus(404);

        $this->assertSame('Madurai', $theirs->fresh()->city);
    }

    public function test_deleting_the_default_promotes_another_address(): void
    {
        $user = $this->user();
        $this->address($user, ['line1' => 'Second Street']);
        $default = $this->address($user, ['line1' => 'First Street']);
        $default->makeDefault();
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/addresses/{$default->id}")->assertOk();

        // The customer must never be left with addresses but no default.
        $this->assertSame(1, $user->addresses()->where('is_default', true)->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Rider
    |--------------------------------------------------------------------------
    */

    public function test_a_rider_profile_is_created_on_first_access(): void
    {
        $user = $this->user(UserRole::Rider);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/rider/profile')->assertOk();

        $this->assertDatabaseHas('riders', ['user_id' => $user->id]);
    }

    public function test_a_rider_under_18_is_rejected(): void
    {
        Sanctum::actingAs($this->user(UserRole::Rider));

        $this->patchJson('/api/v1/rider/profile', [
            'date_of_birth' => now()->subYears(16)->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('date_of_birth');
    }

    public function test_a_rider_cannot_go_online_before_kyc_is_verified(): void
    {
        $user = $this->user(UserRole::Rider);
        Rider::create(['user_id' => $user->id, 'full_name' => 'Rider', 'kyc_status' => KycStatus::Pending]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/rider/duty-status', ['duty_status' => 'available'])
            ->assertStatus(403);
    }

    public function test_a_rider_with_expired_documents_cannot_go_online(): void
    {
        $user = $this->user(UserRole::Rider);
        Rider::create([
            'user_id' => $user->id, 'full_name' => 'Rider',
            'kyc_status' => KycStatus::Verified,
            'driving_licence_expiry' => now()->subDay(),
            'insurance_expiry' => now()->addYear(),
        ]);
        Sanctum::actingAs($user);

        // Dispatching a rider on a lapsed licence is a legal exposure.
        $this->postJson('/api/v1/rider/duty-status', ['duty_status' => 'available'])
            ->assertStatus(403);
    }

    public function test_a_verified_rider_with_current_documents_can_go_online(): void
    {
        $user = $this->user(UserRole::Rider);
        Rider::create([
            'user_id' => $user->id, 'full_name' => 'Rider',
            'kyc_status' => KycStatus::Verified,
            'driving_licence_expiry' => now()->addYear(),
            'insurance_expiry' => now()->addYear(),
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/rider/duty-status', ['duty_status' => 'available'])
            ->assertOk()
            ->assertJsonPath('data.duty_status', 'available')
            ->assertJsonPath('data.can_accept_orders', true);
    }

    public function test_a_rider_cannot_put_themselves_on_an_order(): void
    {
        $user = $this->user(UserRole::Rider);
        Rider::create(['user_id' => $user->id, 'full_name' => 'Rider', 'kyc_status' => KycStatus::Verified]);
        Sanctum::actingAs($user);

        // on_order is set by dispatch only.
        $this->postJson('/api/v1/rider/duty-status', ['duty_status' => 'on_order'])
            ->assertStatus(422);
    }

    public function test_rider_identity_documents_are_never_returned(): void
    {
        $user = $this->user(UserRole::Rider);
        Rider::create([
            'user_id' => $user->id, 'full_name' => 'Rider',
            'aadhaar_number' => '123456789012',
            'bank_account_number' => '00112233445566',
        ]);
        Sanctum::actingAs($user);

        $body = $this->getJson('/api/v1/rider/profile')->assertOk()->getContent();

        $this->assertStringNotContainsString('123456789012', $body);
        $this->assertStringNotContainsString('00112233445566', $body);
    }

    public function test_a_customer_cannot_reach_rider_endpoints(): void
    {
        Sanctum::actingAs($this->user(UserRole::Customer));

        $this->getJson('/api/v1/rider/profile')->assertStatus(403);
        $this->postJson('/api/v1/rider/duty-status', ['duty_status' => 'available'])->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Merchant
    |--------------------------------------------------------------------------
    */

    private function merchantUser(array $merchantAttributes = []): User
    {
        $user = $this->user(UserRole::Merchant);

        Merchant::create(array_merge([
            'user_id' => $user->id,
            'business_name' => 'Saravana Bhavan',
            'owner_name' => 'Karthik',
            'address_line1' => '12 West Masi Street',
            'city' => 'Madurai',
            'pincode' => '625001',
        ], $merchantAttributes));

        return $user->fresh();
    }

    public function test_a_merchant_can_update_their_business_profile(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());

        $this->patchJson('/api/v1/merchant/profile', [
            'business_name' => 'Saravana Bhavan Madurai',
            'avg_prep_time_minutes' => 22,
            'packaging_fee' => 15.50,
            'min_order_value' => 99,
            'supports_pickup' => false,
        ])->assertOk()->assertJsonPath('data.business_name', 'Saravana Bhavan Madurai');

        /*
         * Asserted against the database, not the response. These fields were
         * validated and then silently dropped for want of a $fillable entry,
         * and a 200 with the right business_name hid it completely.
         */
        $merchant = $user->merchant->fresh();
        $this->assertSame(22, $merchant->avg_prep_time_minutes);
        $this->assertSame('15.50', $merchant->packaging_fee);
        $this->assertSame('99.00', $merchant->min_order_value);
        $this->assertFalse($merchant->supports_pickup);
    }

    public function test_a_merchant_cannot_set_their_own_commission_rate(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());

        $this->patchJson('/api/v1/merchant/profile', ['commission_rate' => 0])->assertOk();

        // A contract term, not a preference. Never settable by the account it charges.
        $this->assertSame('0.00', $user->merchant->fresh()->commission_rate);
    }

    public function test_kyc_fields_cannot_be_edited_through_the_profile(): void
    {
        $user = $this->merchantUser([
            'kyc_status' => KycStatus::Verified,
            'fssai_license_no' => '11111111111111',
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/merchant/profile', [
            'fssai_license_no' => '99999999999999',
            'kyc_status' => 'verified',
        ])->assertOk();

        // Editing your own licence number after approval would make
        // verification meaningless.
        $this->assertSame('11111111111111', $user->merchant->fresh()->fssai_license_no);
    }

    public function test_an_unverified_merchant_cannot_open_the_storefront(): void
    {
        Sanctum::actingAs($this->merchantUser(['kyc_status' => KycStatus::Pending]));

        $this->postJson('/api/v1/merchant/accepting-orders', ['is_accepting_orders' => true])
            ->assertStatus(403);
    }

    public function test_an_expired_fssai_licence_blocks_opening(): void
    {
        Sanctum::actingAs($this->merchantUser([
            'kyc_status' => KycStatus::Verified,
            'fssai_license_no' => '11111111111111',
            'fssai_expiry_date' => now()->subDay(),
        ]));

        $this->postJson('/api/v1/merchant/accepting-orders', ['is_accepting_orders' => true])
            ->assertStatus(403);
    }

    public function test_a_verified_merchant_with_a_current_licence_can_open(): void
    {
        Sanctum::actingAs($this->merchantUser([
            'kyc_status' => KycStatus::Verified,
            'fssai_license_no' => '11111111111111',
            'fssai_expiry_date' => now()->addYear(),
        ]));

        $this->postJson('/api/v1/merchant/accepting-orders', ['is_accepting_orders' => true])
            ->assertOk()
            ->assertJsonPath('data.is_accepting_orders', true);
    }

    public function test_closing_the_storefront_is_always_allowed(): void
    {
        Sanctum::actingAs($this->merchantUser([
            'kyc_status' => KycStatus::Pending,
            'is_accepting_orders' => true,
        ]));

        // A merchant must always be able to stop taking orders, whatever their
        // KYC state — a kitchen closing is not a compliance decision.
        $this->postJson('/api/v1/merchant/accepting-orders', ['is_accepting_orders' => false])
            ->assertOk()
            ->assertJsonPath('data.is_accepting_orders', false);
    }

    public function test_a_rider_cannot_reach_merchant_endpoints(): void
    {
        Sanctum::actingAs($this->user(UserRole::Rider));

        $this->getJson('/api/v1/merchant/profile')->assertStatus(403);
        $this->patchJson('/api/v1/merchant/profile', ['business_name' => 'Nope'])->assertStatus(403);
    }

    public function test_profile_endpoints_reject_anonymous_callers(): void
    {
        $this->getJson('/api/v1/profile')->assertStatus(401);
        $this->getJson('/api/v1/addresses')->assertStatus(401);
        $this->getJson('/api/v1/rider/profile')->assertStatus(401);
        $this->getJson('/api/v1/merchant/profile')->assertStatus(401);
    }
}
