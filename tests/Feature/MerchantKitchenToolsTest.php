<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Merchant;
use App\Models\Order;
use Laravel\Sanctum\Sanctum;

/**
 * The small things a kitchen needs mid-service.
 */
class MerchantKitchenToolsTest extends CheckoutTest
{
    private function placeOrder(Merchant $shop): array
    {
        Sanctum::actingAs($customer = $this->customer());

        $address = $customer->addresses()->create([
            'label' => 'home', 'contact_name' => 'Meena', 'contact_phone' => '9876543210',
            'line1' => '4 Gandhi Nagar', 'city' => 'Madurai', 'pincode' => '625020',
            'latitude' => 9.9200, 'longitude' => 78.1195,
        ]);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", ['menu_item_id' => $this->dish($shop)->id])
            ->assertCreated();

        return $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery', 'payment_method' => 'cod', 'address_id' => $address->id,
        ])->assertCreated()->json('data');
    }

    public function test_a_merchant_can_ring_the_customer_about_a_live_order(): void
    {
        $shop = $this->restaurant();
        $order = $this->placeOrder($shop);

        // Without this a merchant whose dish runs out mid-cook can only cancel
        // or wait — there is nobody they can reach.
        $this->actingAs($shop->user)->get("/merchants/orders/{$order['id']}")
            ->assertOk()
            ->assertSee('tel:9876543210', false)
            ->assertSee('Meena');
    }

    public function test_the_number_disappears_once_the_order_is_finished(): void
    {
        $shop = $this->restaurant();
        $order = $this->placeOrder($shop);

        Order::find($order['id'])->update(['status' => OrderStatus::Delivered]);

        // A history page holding every customer's phone number is a list
        // nobody meant to build.
        $this->actingAs($shop->user)->get("/merchants/orders/{$order['id']}")
            ->assertOk()
            ->assertDontSee('tel:9876543210', false);
    }

    public function test_a_whole_category_can_be_marked_out_of_stock(): void
    {
        $shop = $this->restaurant();
        $biryani = $shop->categories()->create(['name' => 'Biryani']);
        $shop->menuItems()->create(['category_id' => $biryani->id, 'name' => 'Chicken Biryani', 'price' => 200]);
        $shop->menuItems()->create(['category_id' => $biryani->id, 'name' => 'Mutton Biryani', 'price' => 260]);
        $elsewhere = $shop->menuItems()->create(['name' => 'Filter Coffee', 'price' => 20]);

        // "No biryani today" is one decision, not one click per dish.
        $this->actingAs($shop->user)
            ->post("/merchants/menu/categories/{$biryani->id}/availability", ['is_available' => 0])
            ->assertRedirect();

        $this->assertSame(0, $shop->menuItems()->where('category_id', $biryani->id)->where('is_available', true)->count());
        $this->assertTrue($elsewhere->fresh()->is_available);
    }

    public function test_the_uncategorised_group_can_be_switched_too(): void
    {
        $shop = $this->restaurant();
        $loose = $shop->menuItems()->create(['name' => 'Filter Coffee', 'price' => 20]);

        // It is a real part of the menu and would otherwise have no way off.
        $this->actingAs($shop->user)
            ->post('/merchants/menu/categories/0/availability', ['is_available' => 0])
            ->assertRedirect();

        $this->assertFalse($loose->fresh()->is_available);
    }

    public function test_bulk_availability_cannot_reach_another_merchants_menu(): void
    {
        $victim = $this->restaurant();
        $category = $victim->categories()->create(['name' => 'Biryani']);
        $dish = $victim->menuItems()->create(['category_id' => $category->id, 'name' => 'Chicken Biryani', 'price' => 200]);

        $this->actingAs($this->restaurant()->user)
            ->post("/merchants/menu/categories/{$category->id}/availability", ['is_available' => 0])
            ->assertNotFound();

        $this->assertTrue($dish->fresh()->is_available);
    }

    public function test_the_queue_carries_a_marker_for_the_newest_order(): void
    {
        $shop = $this->restaurant();
        $order = $this->placeOrder($shop);

        // The chime compares this against what the browser saw last, so it
        // only ever fires for something genuinely new.
        $this->actingAs($shop->user)->get('/merchants/orders')
            ->assertOk()
            ->assertSee('id="newest-order"', false)
            ->assertSee('data-id="'.$order['id'].'"', false);
    }
}
