<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * The food licence, which gates a merchant trading at all.
 */
class AdminComplianceTest extends CheckoutTest
{
    private function admin(): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'Admin '.$n,
            'phone' => '97970000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "compliance{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);
    }

    public function test_a_merchant_without_a_licence_cannot_trade_at_all(): void
    {
        $shop = $this->restaurant();
        $shop->forceFill(['fssai_license_no' => null, 'fssai_expiry_date' => null])->save();
        $shop->update(['is_accepting_orders' => false]);

        // Registering through the website never captures a licence, so this is
        // the state every real merchant starts in.
        $this->actingAs($shop->user)
            ->post('/merchants/accepting-orders', ['is_accepting_orders' => 1])
            ->assertSessionHasErrors('is_accepting_orders');

        $this->assertFalse($shop->fresh()->is_accepting_orders);
    }

    public function test_an_admin_records_the_licence_and_the_merchant_can_then_open(): void
    {
        $shop = $this->restaurant();
        $shop->forceFill(['fssai_license_no' => null, 'fssai_expiry_date' => null])->save();
        $shop->update(['is_accepting_orders' => false]);

        $this->actingAs($this->admin())->post("/admin/merchants/{$shop->id}/compliance", [
            'fssai_license_no' => '12345678901234',
            'fssai_expiry_date' => now()->addYear()->toDateString(),
            'gstin' => '33AAAAA0000A1Z5',
        ])->assertRedirect();

        $shop->refresh();
        $this->assertTrue($shop->hasValidFssai());

        $this->actingAs($shop->user)
            ->post('/merchants/accepting-orders', ['is_accepting_orders' => 1])
            ->assertSessionHasNoErrors();

        $this->assertTrue($shop->fresh()->is_accepting_orders);
    }

    public function test_a_licence_number_must_look_like_one(): void
    {
        $shop = $this->restaurant();

        $this->actingAs($this->admin())->post("/admin/merchants/{$shop->id}/compliance", [
            'fssai_license_no' => '123',
            'fssai_expiry_date' => now()->addYear()->toDateString(),
        ])->assertSessionHasErrors('fssai_license_no');
    }

    public function test_an_expired_licence_is_refused_at_the_point_of_entry(): void
    {
        $shop = $this->restaurant();

        // Recording a lapsed licence would let a merchant trade on paperwork
        // that is not valid.
        $this->actingAs($this->admin())->post("/admin/merchants/{$shop->id}/compliance", [
            'fssai_license_no' => '12345678901234',
            'fssai_expiry_date' => now()->subDay()->toDateString(),
        ])->assertSessionHasErrors('fssai_expiry_date');
    }

    public function test_a_merchant_cannot_write_their_own_licence(): void
    {
        $shop = $this->restaurant();
        $shop->forceFill(['fssai_license_no' => '11111111111111'])->save();

        // Through the portal profile form...
        $this->actingAs($shop->user)->patch('/merchants/profile', [
            'business_name' => 'Ponnusamy Hotel', 'address_line1' => '1 Main Road',
            'city' => 'Madurai', 'pincode' => '625001',
            'latitude' => 9.9195, 'longitude' => 78.1193,
            'avg_prep_time_minutes' => 20, 'packaging_fee' => 0, 'min_order_value' => 0,
            'fssai_license_no' => '99999999999999',
        ])->assertRedirect();

        // ...and through the API.
        Sanctum::actingAs($shop->user);
        $this->patchJson('/api/v1/merchant/profile', ['fssai_license_no' => '99999999999999'])->assertOk();

        // A merchant who could type their own number would make verification
        // meaningless.
        $this->assertSame('11111111111111', $shop->fresh()->fssai_license_no);
    }

    public function test_only_an_admin_may_record_it(): void
    {
        $shop = $this->restaurant();

        foreach ([$shop->user, $this->customer()] as $user) {
            $this->actingAs($user)->post("/admin/merchants/{$shop->id}/compliance", [
                'fssai_license_no' => '12345678901234',
                'fssai_expiry_date' => now()->addYear()->toDateString(),
            ])->assertForbidden();
        }
    }
}
