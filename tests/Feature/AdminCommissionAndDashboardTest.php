<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\DashboardService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

class AdminCommissionAndDashboardTest extends CheckoutTest
{
    private function admin(): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'Admin '.$n,
            'phone' => '97960000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "dash{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);
    }

    public function test_a_new_merchant_is_not_left_on_zero_commission(): void
    {
        config(['checkout.default_commission_rate' => 15]);

        $this->post('/merchants/register', [
            'owner_name' => 'Veera', 'phone' => '9876500111', 'email' => 'newshop@example.in',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'business_name' => 'New Hotel', 'address_line1' => '2 Market Road',
            'city' => 'Madurai', 'pincode' => '625001',
        ])->assertRedirect();

        // The column defaults to 0, which would mean the platform earning
        // nothing until somebody noticed.
        $this->assertMoney(15.0, Merchant::where('business_name', 'New Hotel')->sole()->commission_rate);
    }

    public function test_a_merchant_cannot_set_their_own_commission(): void
    {
        Sanctum::actingAs($owner = $this->restaurant()->user);

        $this->patchJson('/api/v1/merchant/profile', ['commission_rate' => 0])->assertOk();

        // Silently ignored, not accepted: it is a contract term, not a
        // preference of the account it charges.
        $this->assertMoney(0.0, $owner->merchant->fresh()->commission_rate);
    }

    public function test_admin_sets_the_rate_and_it_applies_to_the_next_order(): void
    {
        $shop = $this->restaurant();

        $this->actingAs($this->admin())
            ->post("/admin/merchants/{$shop->id}/terms", ['commission_rate' => 20])
            ->assertRedirect();

        $this->assertMoney(20.0, $shop->fresh()->commission_rate);

        Sanctum::actingAs($customer = $this->customer());
        $address = $customer->addresses()->create([
            'label' => 'home', 'line1' => '4 Gandhi Nagar', 'city' => 'Madurai',
            'pincode' => '625020', 'latitude' => 9.9200, 'longitude' => 78.1195,
        ]);
        $dish = $this->dish($shop);
        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", ['menu_item_id' => $dish->id, 'quantity' => 2])
            ->assertCreated();

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery', 'payment_method' => 'cod', 'address_id' => $address->id,
        ])->assertCreated();

        // 20% of 400 items + 0 packaging.
        $this->assertMoney(80.0, Order::sole()->commission_amount);
    }

    public function test_an_absurd_rate_is_refused(): void
    {
        $shop = $this->restaurant();

        // A fat-fingered 150 would take more than the order is worth.
        $this->actingAs($this->admin())
            ->post("/admin/merchants/{$shop->id}/terms", ['commission_rate' => 150])
            ->assertSessionHasErrors('commission_rate');

        $this->assertMoney(0.0, $shop->fresh()->commission_rate);
    }

    public function test_only_an_admin_may_change_the_rate(): void
    {
        $shop = $this->restaurant();

        foreach ([$shop->user, $this->customer()] as $user) {
            $this->actingAs($user)
                ->post("/admin/merchants/{$shop->id}/terms", ['commission_rate' => 0])
                ->assertForbidden();
        }
    }

    public function test_the_backfill_only_touches_merchants_on_zero(): void
    {
        $untouched = $this->restaurant();
        $untouched->forceFill(['commission_rate' => 25])->save();
        $zero = $this->restaurant();

        $this->artisan('nexmile:backfill-commission', ['--rate' => 12])
            ->expectsConfirmation('Set 1 merchant(s) to 12%?', 'yes')
            ->assertSuccessful();

        $this->assertMoney(12.0, $zero->fresh()->commission_rate);
        $this->assertMoney(25.0, $untouched->fresh()->commission_rate);
    }

    public function test_the_dashboard_counts_delivered_money_not_orders_in_progress(): void
    {
        $shop = $this->restaurant();
        $shop->forceFill(['commission_rate' => 20])->save();

        // Delivered — counts.
        Order::create([
            'order_number' => 'NXDASH1', 'user_id' => $this->customer()->id, 'merchant_id' => $shop->id,
            'status' => 'delivered', 'grand_total' => 500, 'commission_amount' => 80,
            'merchant_payout' => 320, 'delivery_fee' => 25,
            'placed_at' => now(), 'delivered_at' => now(),
        ]);

        // Still in a kitchen — must not be counted as money.
        Order::create([
            'order_number' => 'NXDASH2', 'user_id' => $this->customer()->id, 'merchant_id' => $shop->id,
            'status' => 'preparing', 'grand_total' => 900, 'commission_amount' => 150,
            'merchant_payout' => 600, 'placed_at' => now(),
        ]);

        $page = $this->actingAs($this->admin())->get('/admin/dashboard')->assertOk();

        $page->assertSee('₹500.00')      // gross
            ->assertSee('₹80.00')        // commission
            ->assertDontSee('₹1,400.00'); // both added together

        $stats = app(DashboardService::class)->forDay();
        $this->assertSame(2, $stats['orders']['placed']);
        $this->assertSame(1, $stats['orders']['delivered']);
        $this->assertSame(1, $stats['orders']['in_flight']);
    }

    public function test_the_dashboard_warns_when_delivered_orders_earned_nothing(): void
    {
        $shop = $this->restaurant();

        Order::create([
            'order_number' => 'NXDASH3', 'user_id' => $this->customer()->id, 'merchant_id' => $shop->id,
            'status' => 'delivered', 'grand_total' => 400, 'commission_amount' => 0,
            'merchant_payout' => 400, 'placed_at' => now(), 'delivered_at' => now(),
        ]);

        $this->actingAs($this->admin())->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('those restaurants are on 0%', false);
    }

    public function test_the_dashboard_can_look_at_an_earlier_day(): void
    {
        $shop = $this->restaurant();

        Order::create([
            'order_number' => 'NXDASH4', 'user_id' => $this->customer()->id, 'merchant_id' => $shop->id,
            'status' => 'delivered', 'grand_total' => 250, 'commission_amount' => 30,
            'merchant_payout' => 220,
            'placed_at' => now()->subDays(3), 'delivered_at' => now()->subDays(3),
        ]);

        $day = Carbon::now()->subDays(3)->toDateString();

        $this->actingAs($this->admin())->get("/admin/dashboard?day={$day}")
            ->assertOk()
            ->assertSee('₹250.00');

        // And today shows nothing from that day.
        $this->actingAs($this->admin())->get('/admin/dashboard')
            ->assertOk()
            ->assertDontSee('₹250.00');
    }

    public function test_the_dashboard_is_closed_to_everyone_else(): void
    {
        foreach ([$this->customer(), $this->restaurant()->user] as $user) {
            $this->actingAs($user)->get('/admin/dashboard')->assertForbidden();
        }
    }
}
