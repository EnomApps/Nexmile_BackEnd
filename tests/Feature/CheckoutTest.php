<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

class CheckoutTest extends CartTest
{
    /** A few metres from the restaurant in CartTest. */
    private function address(User $user, float $lat = 9.9200, float $lng = 78.1195): Address
    {
        return $user->addresses()->create([
            'label' => 'home',
            'contact_name' => 'Meena',
            'contact_phone' => '9876543210',
            'line1' => '4 Gandhi Nagar',
            'city' => 'Madurai',
            'pincode' => '625020',
            'latitude' => $lat,
            'longitude' => $lng,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function checkout(Merchant $shop, Address $address, array $overrides = []): TestResponse
    {
        return $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery',
            'payment_method' => 'cod',
            'address_id' => $address->id,
            ...$overrides,
        ]);
    }

    private function fillCart(Merchant $shop, int $quantity = 2): void
    {
        $dish = $this->dish($shop);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $dish->id,
            'quantity' => $quantity,
        ])->assertCreated();
    }

    public function test_placing_an_order_snapshots_the_menu_and_empties_the_cart(): void
    {
        Sanctum::actingAs($user = $this->customer());
        $shop = $this->restaurant(['packaging_fee' => 10]);
        // Not mass-assignable on purpose: commission is a contract term, not a
        // merchant preference, and must never be settable by the account it
        // charges.
        $shop->forceFill(['commission_rate' => 20])->save();
        $address = $this->address($user);
        $this->fillCart($shop);

        $body = $this->checkout($shop, $address)->assertCreated()->json('data');

        $order = Order::sole();
        $this->assertSame(OrderStatus::Placed, $order->status);
        $this->assertMoney(430.0, $body['grand_total']);
        $this->assertNotNull($order->placed_at);
        $this->assertNotNull($order->pickup_code);

        // Commission is charged on food and packaging, not on the delivery fee
        // — that pays the rider and is not the merchant's revenue.
        $this->assertMoney(82.0, $order->commission_amount);
        $this->assertMoney(328.0, $order->merchant_payout);

        // The address is copied, not referenced.
        $this->assertSame('4 Gandhi Nagar', $order->delivery_line1);
        $this->assertNotNull($order->distance_metres);

        // The cart is consumed, so tapping back cannot place it twice.
        $this->getJson("/api/v1/restaurants/{$shop->id}/cart")
            ->assertOk()
            ->assertJsonPath('data.items', []);
    }

    public function test_a_renamed_or_repriced_dish_does_not_rewrite_a_past_order(): void
    {
        Sanctum::actingAs($user = $this->customer());
        $shop = $this->restaurant();
        $address = $this->address($user);
        $this->fillCart($shop, 1);

        $this->checkout($shop, $address)->assertCreated();

        $shop->menuItems()->first()->update(['name' => 'Mutton Biryani', 'price' => 500]);

        $line = Order::sole()->items()->sole();
        $this->assertSame('Chicken Biryani', $line->name);
        $this->assertMoney(200.0, $line->unit_price);
    }

    public function test_the_order_reaches_the_merchants_live_queue(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $shop = $this->restaurant();
        $address = $this->address($customer);
        $this->fillCart($shop);

        $this->checkout($shop, $address)->assertCreated();

        // The whole point of EP5: a real ticket, not nexmile:demo-order.
        Sanctum::actingAs($shop->user);
        $this->getJson('/api/v1/merchant/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'placed');
    }

    public function test_an_address_outside_the_radius_is_refused(): void
    {
        Sanctum::actingAs($user = $this->customer());
        $shop = $this->restaurant();
        // ~2.2 km north — outside the 1 km promise.
        $far = $this->address($user, 9.9395, 78.1193);
        $this->fillCart($shop);

        $this->checkout($shop, $far)
            ->assertStatus(422)
            ->assertJsonValidationErrors('address_id');

        $this->assertSame(0, Order::count());
    }

    public function test_a_closed_kitchen_refuses_the_order(): void
    {
        Sanctum::actingAs($user = $this->customer());
        $shop = $this->restaurant();
        $address = $this->address($user);
        $this->fillCart($shop);

        Carbon::setTestNow(Carbon::parse('2026-08-05 20:00:00')); // Wednesday
        $shop->operatingHours()->create([
            'day_of_week' => 3, 'opens_at' => '11:00', 'closes_at' => '15:00', 'is_closed' => false,
        ]);

        $this->checkout($shop, $address)->assertStatus(422)->assertJsonValidationErrors('merchant');

        Carbon::setTestNow();
        $this->assertSame(0, Order::count());
    }

    public function test_a_merchant_that_stopped_taking_orders_refuses_the_order(): void
    {
        Sanctum::actingAs($user = $this->customer());
        $shop = $this->restaurant();
        $address = $this->address($user);
        $this->fillCart($shop);

        $shop->update(['is_accepting_orders' => false]);

        $this->checkout($shop, $address)->assertStatus(422)->assertJsonValidationErrors('merchant');
    }

    public function test_an_item_that_sold_out_while_shopping_blocks_checkout(): void
    {
        Sanctum::actingAs($user = $this->customer());
        $shop = $this->restaurant();
        $address = $this->address($user);
        $this->fillCart($shop);

        $shop->menuItems()->first()->update(['is_available' => false]);

        // The gap between adding and paying is where a bad ticket reaches a
        // kitchen, so everything is re-checked here.
        $this->checkout($shop, $address)
            ->assertStatus(422)
            ->assertJsonValidationErrors('cart');
    }

    public function test_a_basket_below_the_minimum_is_refused(): void
    {
        Sanctum::actingAs($user = $this->customer());
        $shop = $this->restaurant(['min_order_value' => 500]);
        $address = $this->address($user);
        $this->fillCart($shop, 1);

        $this->checkout($shop, $address)->assertStatus(422)->assertJsonValidationErrors('cart');
    }

    public function test_an_empty_cart_cannot_be_checked_out(): void
    {
        Sanctum::actingAs($user = $this->customer());
        $shop = $this->restaurant();

        $this->checkout($shop, $this->address($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cart');
    }

    public function test_an_unavailable_payment_method_is_refused(): void
    {
        // Stated rather than assumed: a subclass that switches a gateway on
        // would otherwise silently turn this into a test of nothing.
        config(['payments.gateway' => null]);

        Sanctum::actingAs($user = $this->customer());
        $shop = $this->restaurant();
        $address = $this->address($user);
        $this->fillCart($shop);

        // Offering a method that cannot complete loses the basket at the last
        // step, which is worse than not offering it.
        $this->checkout($shop, $address, ['payment_method' => 'upi'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');
    }

    public function test_someone_elses_address_cannot_be_used(): void
    {
        $victim = $this->customer();
        $stranger = $this->address($victim);

        Sanctum::actingAs($this->customer());
        $shop = $this->restaurant();
        $this->fillCart($shop);

        // Not a way to have food sent to a stranger.
        $this->checkout($shop, $stranger)->assertNotFound();
    }

    public function test_pickup_needs_no_address_and_pays_no_delivery_fee(): void
    {
        Sanctum::actingAs($this->customer());
        $shop = $this->restaurant();
        $this->fillCart($shop, 1);

        $body = $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'pickup',
            'payment_method' => 'cod',
        ])->assertCreated()->json('data');

        $this->assertMoney(0.0, $body['delivery_fee']);
        $this->assertNull(Order::sole()->delivery_line1);
    }

    public function test_a_customer_can_cancel_before_the_restaurant_accepts(): void
    {
        Sanctum::actingAs($user = $this->customer());
        $shop = $this->restaurant();
        $address = $this->address($user);
        $this->fillCart($shop);

        $order = $this->checkout($shop, $address)->assertCreated()->json('data');

        $this->postJson("/api/v1/orders/{$order['id']}/cancel", ['reason' => 'Ordered by mistake'])
            ->assertOk();

        $model = Order::sole();
        $this->assertSame(OrderStatus::Cancelled, $model->status);
        $this->assertSame('customer', $model->cancelled_by);
        // Nobody had started work, so nothing is charged.
        $this->assertMoney(0.0, $model->cancellation_fee);
    }

    public function test_a_customer_cannot_cancel_once_the_kitchen_has_started(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $shop = $this->restaurant();
        $address = $this->address($customer);
        $this->fillCart($shop);
        $order = $this->checkout($shop, $address)->assertCreated()->json('data');

        Sanctum::actingAs($shop->user);
        $this->postJson("/api/v1/merchant/orders/{$order['id']}/accept")->assertOk();

        Sanctum::actingAs($customer);
        $this->postJson("/api/v1/orders/{$order['id']}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('message', 'The restaurant has already started your order. Call them if something is wrong.');
    }

    public function test_a_customer_only_sees_their_own_orders(): void
    {
        Sanctum::actingAs($victim = $this->customer());
        $shop = $this->restaurant();
        $this->fillCart($shop);
        $order = $this->checkout($shop, $this->address($victim))->assertCreated()->json('data');

        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/orders/{$order['id']}")->assertNotFound();
        $this->getJson("/api/v1/orders/{$order['id']}/track")->assertNotFound();
        $this->postJson("/api/v1/orders/{$order['id']}/cancel")->assertNotFound();
    }

    public function test_tracking_reports_the_current_status(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $shop = $this->restaurant();
        $this->fillCart($shop);
        $order = $this->checkout($shop, $this->address($customer))->assertCreated()->json('data');

        Sanctum::actingAs($shop->user);
        $this->postJson("/api/v1/merchant/orders/{$order['id']}/accept", ['prep_minutes' => 25])->assertOk();

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/orders/{$order['id']}/track")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.estimated_prep_minutes', 25);
    }
}
