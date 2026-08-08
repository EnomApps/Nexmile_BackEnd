<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ItemOptionGroup;
use App\Models\MenuItem;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Money is a JSON number, so ₹400.00 arrives as `400` and decodes as an
     * int — the same trap the apps have to avoid by reading `num`, not
     * `double`. Comparing with assertSame would fail on whole rupees and pass
     * on 92.50, which is the worst possible way round.
     */
    protected function assertMoney(float $expected, mixed $actual, string $label = ''): void
    {
        $this->assertEqualsWithDelta($expected, (float) $actual, 0.001, $label);
    }

    protected function customer(): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'Cust '.$n,
            'phone' => '97810000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "cart{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Customer,
            'status' => UserStatus::Active,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function restaurant(array $attributes = []): Merchant
    {
        static $n = 0;
        $n++;

        $owner = User::create([
            'name' => 'Owner '.$n,
            'phone' => '97820000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "cartowner{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        return Merchant::create([
            'user_id' => $owner->id,
            'business_name' => 'Ponnusamy Hotel',
            'owner_name' => 'Owner',
            'address_line1' => '1 Main Road',
            'city' => 'Madurai',
            'pincode' => '625001',
            'latitude' => 9.9195,
            'longitude' => 78.1193,
            'kyc_status' => KycStatus::Verified,
            'is_accepting_orders' => true,
            'fssai_license_no' => '12345678901234',
            'fssai_expiry_date' => now()->addYear(),
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function dish(Merchant $merchant, array $attributes = []): MenuItem
    {
        return $merchant->menuItems()->create([
            'name' => 'Chicken Biryani',
            'price' => 200,
            'gst_rate' => 5,
            ...$attributes,
        ]);
    }

    protected function spiceGroup(MenuItem $item, bool $required = true): ItemOptionGroup
    {
        $group = $item->optionGroups()->create([
            'name' => 'Spice level',
            'selection' => 'single',
            'is_required' => $required,
            'min_selections' => $required ? 1 : 0,
        ]);

        $group->options()->create(['name' => 'Mild', 'price_delta' => 0]);
        $group->options()->create(['name' => 'Extra spicy', 'price_delta' => 20]);

        return $group->fresh('options');
    }

    public function test_adding_an_item_prices_the_cart_server_side(): void
    {
        Sanctum::actingAs($this->customer());
        $shop = $this->restaurant(['packaging_fee' => 10]);
        $dish = $this->dish($shop);

        $body = $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $dish->id,
            'quantity' => 2,
        ])->assertCreated()->json('data');

        // 2 × 200 = 400 items, +10 packaging, delivery free above 299,
        // tax = 5% of 400 = 20.
        $this->assertMoney(400.0, $body['totals']['items_total']);
        $this->assertMoney(10.0, $body['totals']['packaging_fee']);
        $this->assertMoney(0.0, $body['totals']['delivery_fee']);
        $this->assertMoney(20.0, $body['totals']['tax_total']);
        $this->assertMoney(430.0, $body['totals']['grand_total']);
        $this->assertTrue($body['free_delivery_applied']);
    }

    public function test_a_small_basket_is_charged_delivery(): void
    {
        Sanctum::actingAs($this->customer());
        $shop = $this->restaurant();
        $dish = $this->dish($shop, ['price' => 60]);

        $body = $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $dish->id,
        ])->assertCreated()->json('data');

        // 60 items + 25 delivery, tax = 5% of 60 plus 18% of 25.
        $this->assertMoney(25.0, $body['totals']['delivery_fee']);
        $this->assertMoney(7.5, $body['totals']['tax_total']);
        $this->assertMoney(92.5, $body['totals']['grand_total']);
    }

    public function test_self_pickup_is_never_charged_delivery(): void
    {
        Sanctum::actingAs($this->customer());
        $shop = $this->restaurant();
        $dish = $this->dish($shop, ['price' => 60]);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", ['menu_item_id' => $dish->id])
            ->assertCreated();

        $body = $this->getJson("/api/v1/restaurants/{$shop->id}/cart?fulfilment_type=pickup")
            ->assertOk()->json('data');

        // There is no delivery to pay for.
        $this->assertMoney(0.0, $body['totals']['delivery_fee']);
        $this->assertMoney(63.0, $body['totals']['grand_total']);
    }

    public function test_option_prices_are_added_to_the_line(): void
    {
        Sanctum::actingAs($this->customer());
        $shop = $this->restaurant();
        $dish = $this->dish($shop);
        $spicy = $this->spiceGroup($dish)->options->firstWhere('name', 'Extra spicy');

        $body = $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $dish->id,
            'quantity' => 2,
            'option_ids' => [$spicy->id],
        ])->assertCreated()->json('data');

        // (200 + 20) × 2
        $this->assertMoney(440.0, $body['totals']['items_total']);
        $this->assertMoney(20.0, $body['items'][0]['options_total']);
    }

    public function test_a_required_choice_must_be_made_when_adding(): void
    {
        Sanctum::actingAs($this->customer());
        $shop = $this->restaurant();
        $dish = $this->dish($shop);
        $this->spiceGroup($dish);

        // Leaving this to checkout means discovering at the last step that a
        // dish added five minutes ago was never valid.
        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", ['menu_item_id' => $dish->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('option_ids');
    }

    public function test_a_single_choice_group_refuses_two_selections(): void
    {
        Sanctum::actingAs($this->customer());
        $shop = $this->restaurant();
        $dish = $this->dish($shop);
        $group = $this->spiceGroup($dish);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $dish->id,
            'option_ids' => $group->options->pluck('id')->all(),
        ])->assertStatus(422)->assertJsonValidationErrors('option_ids');
    }

    public function test_identical_lines_merge_and_different_options_do_not(): void
    {
        Sanctum::actingAs($this->customer());
        $shop = $this->restaurant();
        $dish = $this->dish($shop);
        $group = $this->spiceGroup($dish);
        [$mild, $spicy] = [$group->options->firstWhere('name', 'Mild'), $group->options->firstWhere('name', 'Extra spicy')];

        $url = "/api/v1/restaurants/{$shop->id}/cart/items";

        $this->postJson($url, ['menu_item_id' => $dish->id, 'option_ids' => [$mild->id]])->assertCreated();
        $body = $this->postJson($url, ['menu_item_id' => $dish->id, 'option_ids' => [$mild->id]])
            ->assertCreated()->json('data');

        // A cart listing the same thing twice looks like a bug to the person
        // holding the phone.
        $this->assertCount(1, $body['items']);
        $this->assertSame(2, $body['items'][0]['quantity']);

        $body = $this->postJson($url, ['menu_item_id' => $dish->id, 'option_ids' => [$spicy->id]])
            ->assertCreated()->json('data');

        // Two chai with different sugar levels are two lines.
        $this->assertCount(2, $body['items']);
    }

    public function test_an_item_from_another_restaurant_cannot_be_added(): void
    {
        Sanctum::actingAs($this->customer());
        $shop = $this->restaurant();
        $otherDish = $this->dish($this->restaurant());

        // Mixing shops in one cart would break the single-pickup model.
        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", ['menu_item_id' => $otherDish->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('menu_item_id');
    }

    public function test_an_out_of_stock_item_cannot_be_added(): void
    {
        Sanctum::actingAs($this->customer());
        $shop = $this->restaurant();
        $dish = $this->dish($shop, ['is_available' => false]);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", ['menu_item_id' => $dish->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('menu_item_id');
    }

    public function test_an_item_that_sells_out_after_adding_is_named_not_dropped(): void
    {
        Sanctum::actingAs($this->customer());
        $shop = $this->restaurant();
        $dish = $this->dish($shop);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", ['menu_item_id' => $dish->id])->assertCreated();
        $dish->update(['is_available' => false]);

        $body = $this->getJson("/api/v1/restaurants/{$shop->id}/cart")->assertOk()->json('data');

        // A basket that quietly shrinks between screens is worse than one that
        // explains itself.
        $this->assertSame(['Chicken Biryani'], $body['unavailable_items']);
        $this->assertFalse($body['can_checkout']);
    }

    public function test_quantity_zero_removes_the_line(): void
    {
        Sanctum::actingAs($this->customer());
        $shop = $this->restaurant();
        $dish = $this->dish($shop);

        $cart = $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", ['menu_item_id' => $dish->id])
            ->assertCreated()->json('data');

        $line = $cart['items'][0]['cart_item_id'];

        $body = $this->patchJson("/api/v1/restaurants/{$shop->id}/cart/items/{$line}", ['quantity' => 0])
            ->assertOk()->json('data');

        $this->assertSame([], $body['items']);
    }

    public function test_carts_are_kept_separately_per_restaurant(): void
    {
        Sanctum::actingAs($this->customer());
        $first = $this->restaurant();
        $second = $this->restaurant();

        $this->postJson("/api/v1/restaurants/{$first->id}/cart/items", ['menu_item_id' => $this->dish($first)->id])->assertCreated();
        $this->postJson("/api/v1/restaurants/{$second->id}/cart/items", ['menu_item_id' => $this->dish($second)->id])->assertCreated();

        // Glancing at another shop does not empty the basket you started.
        $this->getJson('/api/v1/carts')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_another_customers_cart_line_cannot_be_touched(): void
    {
        $victim = $this->customer();
        $shop = $this->restaurant();
        $dish = $this->dish($shop);

        Sanctum::actingAs($victim);
        $cart = $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", ['menu_item_id' => $dish->id])
            ->assertCreated()->json('data');
        $line = $cart['items'][0]['cart_item_id'];

        Sanctum::actingAs($this->customer());

        $this->patchJson("/api/v1/restaurants/{$shop->id}/cart/items/{$line}", ['quantity' => 9])->assertNotFound();
        $this->deleteJson("/api/v1/restaurants/{$shop->id}/cart/items/{$line}")->assertNotFound();
    }

    public function test_cart_endpoints_reject_anonymous_callers(): void
    {
        $shop = $this->restaurant();

        $this->getJson("/api/v1/restaurants/{$shop->id}/cart")->assertUnauthorized();
    }
}
