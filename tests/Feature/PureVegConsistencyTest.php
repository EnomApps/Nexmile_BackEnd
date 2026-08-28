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

/**
 * A pure vegetarian kitchen and its menu have to agree.
 *
 * Nothing stopped a shop ticking "pure veg" and still selling mutton biryani.
 * A customer filtering veg-only would be sent there, open the menu, and stop
 * trusting the filter — which costs far more than the filter is worth.
 *
 * The rule is enforced from both directions, because a merchant can arrive at
 * the contradiction from either one.
 */
class PureVegConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function merchantUser(bool $pureVeg = false): User
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'name' => 'Owner '.$n,
            'phone' => '97810000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "veg{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        Merchant::create([
            'user_id' => $user->id,
            'business_name' => 'Sri Krishna Bhavan '.$n,
            'owner_name' => 'Owner',
            'address_line1' => '9 Anna Salai',
            'city' => 'Madurai',
            'pincode' => '625001',
            'kyc_status' => KycStatus::Verified,
            'is_pure_veg' => $pureVeg,
        ]);

        return $user->fresh();
    }

    public function test_a_pure_veg_kitchen_cannot_add_a_non_veg_dish(): void
    {
        $user = $this->merchantUser(pureVeg: true);

        $this->actingAs($user)
            ->post('/merchants/menu/items', [
                'name' => 'Mutton Biryani',
                'price' => 220,
                'is_veg' => 0,
            ])
            ->assertSessionHasErrors('is_veg');

        $this->assertSame(0, $user->merchant->menuItems()->count());
    }

    public function test_a_pure_veg_kitchen_can_still_add_veg_dishes(): void
    {
        // The guard must not make a pure veg kitchen unable to build a menu.
        $user = $this->merchantUser(pureVeg: true);

        $this->actingAs($user)
            ->post('/merchants/menu/items', [
                'name' => 'Masala Dosa',
                'price' => 60,
                'is_veg' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $user->merchant->menuItems()->count());
    }

    public function test_a_kitchen_that_is_not_pure_veg_is_unaffected(): void
    {
        $user = $this->merchantUser(pureVeg: false);

        $this->actingAs($user)
            ->post('/merchants/menu/items', [
                'name' => 'Chicken 65',
                'price' => 180,
                'is_veg' => 0,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $user->merchant->menuItems()->count());
    }

    public function test_a_kitchen_with_non_veg_dishes_cannot_declare_itself_pure_veg(): void
    {
        $user = $this->merchantUser(pureVeg: false);
        $user->merchant->menuItems()->create(['name' => 'Chicken 65', 'price' => 180, 'is_veg' => false]);

        $this->actingAs($user)
            ->post('/merchants/storefront/listing', ['is_pure_veg' => 1])
            ->assertSessionHasErrors('is_pure_veg');

        $this->assertFalse($user->merchant->fresh()->is_pure_veg);
    }

    public function test_the_refusal_names_the_dishes_in_the_way(): void
    {
        /*
         * "You have 3 non-veg dishes" sends the merchant hunting. Naming them
         * is the difference between a refusal they can act on and one they
         * argue with.
         */
        $user = $this->merchantUser(pureVeg: false);
        $user->merchant->menuItems()->create(['name' => 'Chicken 65', 'price' => 180, 'is_veg' => false]);

        $errors = $this->actingAs($user)
            ->post('/merchants/storefront/listing', ['is_pure_veg' => 1])
            ->assertSessionHasErrors('is_pure_veg')
            ->getSession()->get('errors');

        $this->assertStringContainsString('Chicken 65', $errors->first('is_pure_veg'));
    }

    public function test_a_kitchen_with_only_veg_dishes_can_declare_itself_pure_veg(): void
    {
        $user = $this->merchantUser(pureVeg: false);
        $user->merchant->menuItems()->create(['name' => 'Idli', 'price' => 30, 'is_veg' => true]);

        $this->actingAs($user)
            ->post('/merchants/storefront/listing', ['is_pure_veg' => 1])
            ->assertSessionHasNoErrors();

        $this->assertTrue($user->merchant->fresh()->is_pure_veg);
    }

    public function test_an_already_pure_veg_kitchen_can_save_other_settings(): void
    {
        /*
         * The check only runs on the transition to pure veg. Otherwise a shop
         * that somehow already holds the contradiction could never save its
         * cost-for-two again — punishing them for our earlier missing guard.
         */
        $user = $this->merchantUser(pureVeg: true);
        $user->merchant->menuItems()->create(['name' => 'Legacy Chicken', 'price' => 180, 'is_veg' => false]);

        $this->actingAs($user)
            ->post('/merchants/storefront/listing', ['is_pure_veg' => 1, 'cost_for_two' => 250])
            ->assertSessionHasNoErrors();

        $this->assertSame(250, $user->merchant->fresh()->cost_for_two);
    }

    public function test_the_api_enforces_the_same_rule(): void
    {
        // The merchant app posts dishes too; a guard only in the portal is a
        // guard with a door beside it.
        $user = $this->merchantUser(pureVeg: true);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/merchant/menu-items', [
            'name' => 'Fish Curry',
            'price' => 200,
            'is_veg' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('is_veg');
    }
}
