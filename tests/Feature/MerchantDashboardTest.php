<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Models\Merchant;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * What a merchant needs on an ordinary day, all of which the portal could not
 * do: stop the queue, see what they earned, and fix their own details.
 */
class MerchantDashboardTest extends CheckoutTest
{
    /** @param array<string, mixed> $attributes */
    private function deliveredOrder(Merchant $shop, array $attributes = []): Order
    {
        static $n = 0;
        $n++;

        return Order::create([
            'order_number' => 'NXMD'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'user_id' => $this->customer()->id,
            'merchant_id' => $shop->id,
            'status' => OrderStatus::Delivered,
            'items_total' => 400, 'packaging_fee' => 10, 'delivery_fee' => 25,
            'grand_total' => 455, 'commission_amount' => 82, 'merchant_payout' => 328,
            'placed_at' => now(), 'delivered_at' => now(),
            ...$attributes,
        ]);
    }

    public function test_a_merchant_can_stop_and_start_taking_orders(): void
    {
        $shop = $this->restaurant();

        $this->actingAs($shop->user)
            ->post('/merchants/accepting-orders', ['is_accepting_orders' => 0])
            ->assertRedirect();

        $this->assertFalse($shop->fresh()->is_accepting_orders);

        $this->actingAs($shop->user)
            ->post('/merchants/accepting-orders', ['is_accepting_orders' => 1])
            ->assertRedirect();

        $this->assertTrue($shop->fresh()->is_accepting_orders);
    }

    public function test_an_unverified_merchant_cannot_open(): void
    {
        $shop = $this->restaurant();
        $shop->update(['kyc_status' => KycStatus::Submitted, 'is_accepting_orders' => false]);

        $this->actingAs($shop->user)
            ->post('/merchants/accepting-orders', ['is_accepting_orders' => 1])
            ->assertSessionHasErrors('is_accepting_orders');

        $this->assertFalse($shop->fresh()->is_accepting_orders);
    }

    public function test_an_expired_fssai_licence_blocks_opening(): void
    {
        $shop = $this->restaurant();
        // A lapsed food licence is a legal problem, not a warning.
        $shop->update(['fssai_expiry_date' => now()->subDay(), 'is_accepting_orders' => false]);

        $this->actingAs($shop->user)
            ->post('/merchants/accepting-orders', ['is_accepting_orders' => 1])
            ->assertSessionHasErrors('is_accepting_orders');
    }

    public function test_the_dashboard_shows_todays_takings(): void
    {
        $shop = $this->restaurant();
        $this->deliveredOrder($shop);

        $this->actingAs($shop->user)->get('/merchants/dashboard')
            ->assertOk()
            ->assertSee('₹328.00')          // payout, not the customer total
            ->assertDontSee('₹455.00');     // grand total is not the merchant's
    }

    public function test_earnings_count_delivered_orders_only(): void
    {
        $shop = $this->restaurant();
        $this->deliveredOrder($shop);
        // Still cooking — a merchant is paid for food that reached someone.
        $this->deliveredOrder($shop, [
            'status' => OrderStatus::Preparing, 'delivered_at' => null,
            'merchant_payout' => 999,
        ]);

        $this->actingAs($shop->user)->get('/merchants/earnings')
            ->assertOk()
            ->assertSee('₹328.00')
            ->assertDontSee('₹999.00');
    }

    public function test_earnings_show_the_commission_that_was_deducted(): void
    {
        $shop = $this->restaurant();
        $shop->forceFill(['commission_rate' => 20])->save();
        $this->deliveredOrder($shop);

        // "Why is my payout less than my sales" has one answer and it belongs
        // on the same screen as the number that prompts it.
        $this->actingAs($shop->user)->get('/merchants/earnings')
            ->assertOk()
            ->assertSee('₹82.00')
            ->assertSee('20%');
    }

    public function test_earnings_can_be_filtered_to_a_date_range(): void
    {
        $shop = $this->restaurant();
        $this->deliveredOrder($shop, [
            'delivered_at' => now()->subDays(10), 'placed_at' => now()->subDays(10),
            'merchant_payout' => 777,
        ]);

        // Default window is the last week, so an older order is excluded.
        $this->actingAs($shop->user)->get('/merchants/earnings')
            ->assertOk()->assertDontSee('₹777.00');

        $from = Carbon::now()->subDays(11)->toDateString();
        $this->actingAs($shop->user)->get("/merchants/earnings?from={$from}")
            ->assertOk()->assertSee('₹777.00');
    }

    public function test_a_merchant_only_sees_their_own_earnings(): void
    {
        $mine = $this->restaurant();
        $theirs = $this->restaurant();
        $this->deliveredOrder($theirs, ['merchant_payout' => 4242]);

        $this->actingAs($mine->user)->get('/merchants/earnings')
            ->assertOk()
            ->assertDontSee('₹4,242.00');
    }

    public function test_a_merchant_can_fix_their_own_details(): void
    {
        $shop = $this->restaurant();

        $this->actingAs($shop->user)->patch('/merchants/profile', [
            'business_name' => 'Ponnusamy Mess',
            'business_phone' => '9876500222',
            'address_line1' => '9 New Street',
            'city' => 'Madurai',
            'pincode' => '625002',
            'latitude' => 9.9300,
            'longitude' => 78.1200,
            'avg_prep_time_minutes' => 25,
            'packaging_fee' => 15,
            'min_order_value' => 100,
        ])->assertRedirect();

        $shop->refresh();
        $this->assertSame('Ponnusamy Mess', $shop->business_name);
        $this->assertSame(25, $shop->avg_prep_time_minutes);
    }

    public function test_a_merchant_cannot_remove_their_own_location(): void
    {
        $shop = $this->restaurant();

        // Without coordinates they are invisible to every customer and cannot
        // work out why, so it is required rather than optional.
        $this->actingAs($shop->user)->patch('/merchants/profile', [
            'business_name' => 'Ponnusamy Hotel',
            'address_line1' => '1 Main Road', 'city' => 'Madurai', 'pincode' => '625001',
            'avg_prep_time_minutes' => 20, 'packaging_fee' => 0, 'min_order_value' => 0,
        ])->assertSessionHasErrors(['latitude', 'longitude']);
    }

    public function test_a_merchant_cannot_set_their_commission_through_the_profile_form(): void
    {
        $shop = $this->restaurant();
        $shop->forceFill(['commission_rate' => 18])->save();

        $this->actingAs($shop->user)->patch('/merchants/profile', [
            'business_name' => 'Ponnusamy Hotel', 'address_line1' => '1 Main Road',
            'city' => 'Madurai', 'pincode' => '625001',
            'latitude' => 9.9195, 'longitude' => 78.1193,
            'avg_prep_time_minutes' => 20, 'packaging_fee' => 0, 'min_order_value' => 0,
            'commission_rate' => 0,
        ])->assertRedirect();

        $this->assertMoney(18.0, $shop->fresh()->commission_rate);
    }

    public function test_a_merchant_can_cancel_an_accepted_order_from_the_portal(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $shop = $this->restaurant();
        $address = $customer->addresses()->create([
            'label' => 'home', 'line1' => '4 Gandhi Nagar', 'city' => 'Madurai',
            'pincode' => '625020', 'latitude' => 9.9200, 'longitude' => 78.1195,
        ]);
        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", ['menu_item_id' => $this->dish($shop)->id])
            ->assertCreated();
        $order = $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery', 'payment_method' => 'cod', 'address_id' => $address->id,
        ])->assertCreated()->json('data');

        $this->actingAs($shop->user)->post("/merchants/orders/{$order['id']}/accept")->assertRedirect();

        // Merchants have no app, so an API-only cancel was unreachable by the
        // person standing in the kitchen.
        $this->actingAs($shop->user)
            ->post("/merchants/orders/{$order['id']}/cancel", ['reason' => 'Gas cylinder ran out, sorry.'])
            ->assertRedirect();

        $this->assertSame(OrderStatus::Cancelled, Order::find($order['id'])->status);
    }

    public function test_merchant_pages_are_closed_to_other_roles(): void
    {
        $customer = $this->customer();

        foreach (['/merchants/earnings', '/merchants/profile'] as $url) {
            $this->actingAs($customer)->get($url)->assertForbidden();
        }
    }
}
