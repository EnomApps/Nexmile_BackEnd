<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ItemOption;
use App\Models\MenuItem;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenuOptionGroupTest extends TestCase
{
    use RefreshDatabase;

    private function merchantUser(): User
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'name' => 'Owner '.$n,
            'phone' => '97680000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "opts{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        Merchant::create([
            'user_id' => $user->id,
            'business_name' => 'Ponnusamy Hotel',
            'owner_name' => 'Owner',
            'address_line1' => '1 Main Road',
            'city' => 'Madurai',
            'pincode' => '625001',
            'kyc_status' => KycStatus::Verified,
        ]);

        return $user->fresh();
    }

    private function item(Merchant $merchant): MenuItem
    {
        return $merchant->menuItems()->create(['name' => 'Dosa', 'price' => 60]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Spice level',
            'selection' => 'single',
            'is_required' => true,
            'options' => [
                ['name' => 'Mild', 'price_delta' => 0],
                ['name' => 'Medium', 'price_delta' => 0],
                ['name' => 'Extra spicy', 'price_delta' => 10],
            ],
            ...$overrides,
        ];
    }

    public function test_a_group_is_created_with_its_options_in_one_call(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());
        $item = $this->item($user->merchant);

        $response = $this->postJson("/api/v1/merchant/menu-items/{$item->id}/option-groups", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Spice level')
            ->assertJsonCount(3, 'data.options');

        // A required group implies at least one choice; the merchant should
        // not have to reason about two fields that must agree.
        $this->assertSame(1, $response->json('data.min_selections'));
        $this->assertEqualsWithDelta(10.0, $response->json('data.options.2.price_delta'), 0.001);
    }

    public function test_the_customer_menu_returns_option_groups(): void
    {
        $user = $this->merchantUser();
        $merchant = $user->merchant;
        $merchant->update(['latitude' => 9.9195, 'longitude' => 78.1193, 'is_accepting_orders' => true]);
        $item = $this->item($merchant);

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/merchant/menu-items/{$item->id}/option-groups", $this->payload())->assertCreated();

        $customer = User::create([
            'name' => 'Cust', 'phone' => '9769000001', 'email' => 'optcust@example.in',
            'password' => 'secret', 'role' => UserRole::Customer, 'status' => UserStatus::Active,
        ]);
        Sanctum::actingAs($customer);

        // Before this existed, option_groups was permanently empty.
        $this->getJson("/api/v1/restaurants/{$merchant->id}/menu")
            ->assertOk()
            ->assertJsonPath('data.menu.0.items.0.option_groups.0.name', 'Spice level')
            ->assertJsonCount(3, 'data.menu.0.items.0.option_groups.0.options');
    }

    public function test_updating_keeps_ids_for_options_that_survive(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());
        $item = $this->item($user->merchant);

        $created = $this->postJson("/api/v1/merchant/menu-items/{$item->id}/option-groups", $this->payload())
            ->assertCreated()->json('data');

        $keep = $created['options'][0];
        $drop = $created['options'][2];

        $this->patchJson("/api/v1/merchant/option-groups/{$created['id']}", [
            'options' => [
                ['id' => $keep['id'], 'name' => 'Mild', 'price_delta' => 0],
                ['name' => 'Fiery', 'price_delta' => 15],
            ],
        ])->assertOk()->assertJsonCount(2, 'data.options');

        // Recreating survivors would break the link from historical order
        // lines, so "how often was this ordered" stops being answerable.
        $this->assertNotNull(ItemOption::find($keep['id']));
        $this->assertNull(ItemOption::find($drop['id']));
    }

    public function test_a_group_a_customer_could_never_satisfy_is_rejected(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());
        $item = $this->item($user->merchant);
        $url = "/api/v1/merchant/menu-items/{$item->id}/option-groups";

        // Single choice, but demands two.
        $this->postJson($url, $this->payload(['max_selections' => 2]))
            ->assertStatus(422)->assertJsonValidationErrors('max_selections');

        // Minimum higher than the number of choices offered.
        $this->postJson($url, $this->payload(['selection' => 'multiple', 'min_selections' => 5]))
            ->assertStatus(422)->assertJsonValidationErrors('min_selections');

        // Required, but "pick none" is allowed.
        $this->postJson($url, $this->payload(['is_required' => true, 'min_selections' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors('min_selections');
    }

    public function test_a_group_must_have_at_least_one_option(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());
        $item = $this->item($user->merchant);

        // A group with no choices is a dead end at checkout.
        $this->postJson("/api/v1/merchant/menu-items/{$item->id}/option-groups", $this->payload(['options' => []]))
            ->assertStatus(422)->assertJsonValidationErrors('options');
    }

    public function test_a_merchant_cannot_touch_another_merchants_groups(): void
    {
        $victim = $this->merchantUser();
        $victimItem = $this->item($victim->merchant);

        Sanctum::actingAs($victim);
        $group = $this->postJson("/api/v1/merchant/menu-items/{$victimItem->id}/option-groups", $this->payload())
            ->assertCreated()->json('data');

        Sanctum::actingAs($this->merchantUser());

        $this->postJson("/api/v1/merchant/menu-items/{$victimItem->id}/option-groups", $this->payload())->assertNotFound();
        $this->patchJson("/api/v1/merchant/option-groups/{$group['id']}", ['name' => 'Hijacked'])->assertNotFound();
        $this->deleteJson("/api/v1/merchant/option-groups/{$group['id']}")->assertNotFound();
        $this->postJson("/api/v1/merchant/options/{$group['options'][0]['id']}/availability", ['is_available' => false])
            ->assertNotFound();

        $this->assertSame('Spice level', ItemOption::find($group['options'][0]['id'])->group->name);
    }

    public function test_an_option_id_from_another_group_is_not_adopted(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());
        $merchant = $user->merchant;

        $first = $this->postJson("/api/v1/merchant/menu-items/{$this->item($merchant)->id}/option-groups", $this->payload())
            ->assertCreated()->json('data');
        $second = $this->postJson("/api/v1/merchant/menu-items/{$this->item($merchant)->id}/option-groups", $this->payload(['name' => 'Size']))
            ->assertCreated()->json('data');

        $stolen = $first['options'][0]['id'];

        $this->patchJson("/api/v1/merchant/option-groups/{$second['id']}", [
            'options' => [['id' => $stolen, 'name' => 'Moved', 'price_delta' => 0]],
        ])->assertOk();

        // The other group's option must be untouched, not relocated.
        $this->assertSame('Mild', ItemOption::find($stolen)->name);
        $this->assertSame($first['id'], ItemOption::find($stolen)->item_option_group_id);
    }

    public function test_one_option_can_be_marked_unavailable_without_the_whole_group(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());
        $item = $this->item($user->merchant);

        $group = $this->postJson("/api/v1/merchant/menu-items/{$item->id}/option-groups", $this->payload())
            ->assertCreated()->json('data');

        // The kitchen runs out of paneer, not of "choose your filling".
        $this->postJson("/api/v1/merchant/options/{$group['options'][1]['id']}/availability", ['is_available' => false])
            ->assertOk();

        $this->assertFalse(ItemOption::find($group['options'][1]['id'])->is_available);
        $this->assertTrue(ItemOption::find($group['options'][0]['id'])->is_available);
    }
}
