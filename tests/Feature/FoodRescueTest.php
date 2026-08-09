<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\MenuItem;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Services\Menu\SurplusService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

/**
 * Food Rescue (EP14) — surplus food, discounted, finite, and on a clock.
 */
class FoodRescueTest extends CheckoutTest
{
    /** @param array<string, mixed> $overrides */
    private function deal(Merchant $shop, array $overrides = []): MenuItem
    {
        $item = $this->dish($shop, ['name' => 'Evening Biryani', 'price' => 200]);

        app(SurplusService::class)->offer($item, [
            'price' => 90,
            'compare_at_price' => 200,
            'surplus_quantity' => 3,
            'surplus_available_from' => now()->subMinutes(5),
            'surplus_available_until' => now()->addHours(2),
            ...$overrides,
        ]);

        return $item->fresh();
    }

    private function addressFor(User $customer): Address
    {
        return $customer->addresses()->create([
            'label' => 'home', 'line1' => '4 Gandhi Nagar', 'city' => 'Madurai',
            'pincode' => '625020', 'latitude' => 9.9200, 'longitude' => 78.1195,
        ]);
    }

    public function test_offering_a_deal_keeps_the_original_price_visible(): void
    {
        $deal = $this->deal($this->restaurant());

        // Without the struck-through price a rescue deal is just a cheap dish.
        $this->assertMoney(90.0, $deal->price);
        $this->assertMoney(200.0, $deal->compare_at_price);
        $this->assertTrue($deal->is_surplus_deal);
    }

    public function test_a_deal_must_be_cheaper_than_the_usual_price(): void
    {
        $shop = $this->restaurant();
        $item = $this->dish($shop, ['price' => 200]);

        $this->expectException(ValidationException::class);

        app(SurplusService::class)->offer($item, [
            'price' => 250, 'compare_at_price' => 200, 'surplus_quantity' => 3,
            'surplus_available_from' => now(), 'surplus_available_until' => now()->addHour(),
        ]);
    }

    public function test_a_window_that_has_already_passed_is_refused(): void
    {
        $shop = $this->restaurant();
        $item = $this->dish($shop);

        $this->expectException(ValidationException::class);

        app(SurplusService::class)->offer($item, [
            'price' => 90, 'compare_at_price' => 200, 'surplus_quantity' => 3,
            'surplus_available_from' => now()->subDays(2),
            'surplus_available_until' => now()->subDay(),
        ]);
    }

    public function test_deals_appear_on_the_nearby_deals_screen(): void
    {
        $shop = $this->restaurant();
        $this->deal($shop);
        $this->dish($shop, ['name' => 'Ordinary Dosa']);

        Sanctum::actingAs($this->customer());

        $body = $this->getJson('/api/v1/restaurants/deals?latitude=9.9195&longitude=78.1193')
            ->assertOk()->json('data');

        $this->assertCount(1, $body);
        $this->assertSame('Evening Biryani', $body[0]['item']['name']);
        $this->assertSame(3, $body[0]['item']['rescue']['portions_left']);
        $this->assertMoney(110.0, $body[0]['item']['rescue']['saving']);
    }

    public function test_an_expired_deal_never_reaches_a_customer(): void
    {
        $shop = $this->restaurant();
        $deal = $this->deal($shop);

        // The window closes while nobody is looking.
        $deal->forceFill(['surplus_available_until' => now()->subMinute()])->save();

        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/restaurants/deals?latitude=9.9195&longitude=78.1193')
            ->assertOk()->assertJsonCount(0, 'data');

        // And the menu says it is not a live deal, so the app cannot advertise
        // something checkout would refuse.
        $this->getJson("/api/v1/restaurants/{$shop->id}/menu")
            ->assertOk()
            ->assertJsonPath('data.menu.0.items.0.is_rescue_deal', false);
    }

    public function test_an_expired_deal_cannot_be_added_to_a_cart(): void
    {
        $shop = $this->restaurant();
        $deal = $this->deal($shop);
        $deal->forceFill(['surplus_available_until' => now()->subMinute()])->save();

        Sanctum::actingAs($this->customer());

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", ['menu_item_id' => $deal->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('menu_item_id');
    }

    public function test_ordering_a_deal_takes_the_portions(): void
    {
        $shop = $this->restaurant();
        $deal = $this->deal($shop);

        Sanctum::actingAs($customer = $this->customer());
        $address = $this->addressFor($customer);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $deal->id, 'quantity' => 2,
        ])->assertCreated();

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery', 'payment_method' => 'cod', 'address_id' => $address->id,
        ])->assertCreated();

        $this->assertSame(1, (int) $deal->fresh()->surplus_quantity);
    }

    public function test_the_last_portions_cannot_be_sold_twice(): void
    {
        $shop = $this->restaurant();
        $deal = $this->deal($shop, ['surplus_quantity' => 2]);

        // Both customers get two portions into a cart while stock exists.
        $carts = [];
        foreach ([0, 1] as $i) {
            Sanctum::actingAs($customer = $this->customer());
            $carts[$i] = $this->addressFor($customer);
            $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
                'menu_item_id' => $deal->id, 'quantity' => 2,
            ])->assertCreated();
        }

        $results = [];
        foreach ([0, 1] as $i) {
            Sanctum::actingAs($carts[$i]->user);
            $results[] = $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
                'fulfilment_type' => 'delivery', 'payment_method' => 'cod', 'address_id' => $carts[$i]->id,
            ])->getStatusCode();
        }

        // One order, one refusal — and never a negative count.
        sort($results);
        $this->assertSame([201, 422], $results);
        $this->assertSame(1, Order::count());
        $this->assertSame(0, (int) $deal->fresh()->surplus_quantity);
    }

    public function test_a_sold_out_deal_blocks_checkout_rather_than_going_negative(): void
    {
        $shop = $this->restaurant();
        $deal = $this->deal($shop, ['surplus_quantity' => 1]);

        Sanctum::actingAs($customer = $this->customer());
        $address = $this->addressFor($customer);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", ['menu_item_id' => $deal->id])
            ->assertCreated();

        // Someone else takes the last one first.
        $deal->forceFill(['surplus_quantity' => 0])->save();

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery', 'payment_method' => 'cod', 'address_id' => $address->id,
        ])->assertStatus(422)->assertJsonValidationErrors('cart');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, (int) $deal->fresh()->surplus_quantity);
    }

    public function test_withdrawing_restores_the_usual_price(): void
    {
        $shop = $this->restaurant();
        $deal = $this->deal($shop);

        app(SurplusService::class)->withdraw($deal);

        $deal->refresh();
        $this->assertFalse($deal->is_surplus_deal);
        $this->assertMoney(200.0, $deal->price);
        $this->assertNull($deal->compare_at_price);
        $this->assertNull($deal->surplus_available_until);
    }

    public function test_a_merchant_manages_deals_from_the_portal(): void
    {
        $shop = $this->restaurant();
        $item = $this->dish($shop, ['name' => 'Evening Biryani', 'price' => 200]);

        $this->actingAs($shop->user)->post("/merchants/food-rescue/{$item->id}", [
            'price' => 90,
            'compare_at_price' => 200,
            'surplus_quantity' => 4,
            'surplus_available_from' => now()->format('Y-m-d\TH:i'),
            'surplus_available_until' => now()->addHours(3)->format('Y-m-d\TH:i'),
        ])->assertRedirect();

        $this->assertTrue($item->fresh()->is_surplus_deal);

        $this->actingAs($shop->user)->get('/merchants/food-rescue')
            ->assertOk()->assertSee('Evening Biryani');

        $this->actingAs($shop->user)->delete("/merchants/food-rescue/{$item->id}")->assertRedirect();

        $this->assertFalse($item->fresh()->is_surplus_deal);
    }

    public function test_a_merchant_cannot_offer_another_merchants_dish(): void
    {
        $victim = $this->restaurant();
        $dish = $this->dish($victim);

        $this->actingAs($this->restaurant()->user)
            ->post("/merchants/food-rescue/{$dish->id}", [
                'price' => 1, 'surplus_quantity' => 1,
                'surplus_available_from' => now()->format('Y-m-d\TH:i'),
                'surplus_available_until' => now()->addHour()->format('Y-m-d\TH:i'),
            ])->assertNotFound();

        $this->assertFalse($dish->fresh()->is_surplus_deal);
    }

    public function test_a_deal_that_has_not_started_is_not_live_yet(): void
    {
        $shop = $this->restaurant();
        $deal = $this->deal($shop, [
            'surplus_available_from' => now()->addHours(2),
            'surplus_available_until' => now()->addHours(5),
        ]);

        $this->assertFalse(app(SurplusService::class)->isLive($deal));
        $this->assertTrue(app(SurplusService::class)->isLive($deal, Carbon::now()->addHours(3)));
    }
}
