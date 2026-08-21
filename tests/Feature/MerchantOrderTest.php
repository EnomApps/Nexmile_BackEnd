<?php

namespace Tests\Feature;

use App\Enums\FulfilmentType;
use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MerchantOrderTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'User '.$n,
            'phone' => '98780000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "order{$n}@example.in",
            'password' => 'secret',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    private function merchantUser(): User
    {
        $user = $this->user(UserRole::Merchant);

        Merchant::create([
            'user_id' => $user->id,
            'business_name' => 'Ponnusamy Hotel',
            'owner_name' => 'Owner',
            'address_line1' => '9 Anna Salai',
            'city' => 'Madurai',
            'pincode' => '625001',
            'kyc_status' => KycStatus::Verified,
            'is_accepting_orders' => true,
        ]);

        return $user->fresh();
    }

    private function order(Merchant $merchant, OrderStatus $status = OrderStatus::Placed): Order
    {
        static $n = 0;
        $n++;

        $order = $merchant->orders()->create([
            'order_number' => 'NX'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'user_id' => $this->user(UserRole::Customer)->id,
            'status' => $status,
            'fulfilment_type' => FulfilmentType::Delivery,
            'delivery_contact_name' => 'Meena',
            'delivery_line1' => '4 Gandhi Nagar',
            'delivery_city' => 'Madurai',
            'delivery_pincode' => '625020',
            'items_total' => 300,
            'grand_total' => 340,
            'merchant_payout' => 270,
            'placed_at' => now(),
        ]);

        $order->items()->create([
            'name' => 'Chicken 65',
            'unit_price' => 150,
            'quantity' => 2,
            'line_total' => 300,
        ]);

        return $order->fresh();
    }

    public function test_the_live_queue_hides_unpaid_orders(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());
        $merchant = $user->merchant;

        $paid = $this->order($merchant);
        $this->order($merchant, OrderStatus::PendingPayment);

        $response = $this->getJson('/api/v1/merchant/orders')->assertOk();

        // An order the customer has not paid for is not a kitchen ticket.
        $this->assertSame([$paid->id], array_column($response->json('data'), 'id'));
    }

    public function test_a_merchant_only_sees_their_own_orders(): void
    {
        $victim = $this->merchantUser();
        $theirs = $this->order($victim->merchant);

        Sanctum::actingAs($this->merchantUser());

        $this->getJson('/api/v1/merchant/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/merchant/orders/{$theirs->id}")->assertNotFound();
        $this->postJson("/api/v1/merchant/orders/{$theirs->id}/accept")->assertNotFound();

        $this->assertSame(OrderStatus::Placed, $theirs->fresh()->status);
    }

    public function test_accepting_records_the_time_history_and_an_estimate(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());
        $order = $this->order($user->merchant);

        $this->postJson("/api/v1/merchant/orders/{$order->id}/accept", ['prep_minutes' => 30])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $order->refresh();
        $this->assertSame(30, $order->estimated_prep_minutes);
        $this->assertNotNull($order->accepted_at);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'placed',
            'to_status' => 'accepted',
            'changed_by_user_id' => $user->id,
        ]);
    }

    public function test_accepting_without_an_estimate_falls_back_to_the_merchant_average(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());
        $user->merchant->update(['avg_prep_time_minutes' => 45]);
        $order = $this->order($user->merchant);

        $this->postJson("/api/v1/merchant/orders/{$order->id}/accept")->assertOk();

        // The customer always gets an estimate, even on a bare tap.
        $this->assertSame(45, $order->fresh()->estimated_prep_minutes);
    }

    public function test_an_order_cannot_be_accepted_twice(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());
        $order = $this->order($user->merchant, OrderStatus::Accepted);

        $this->postJson("/api/v1/merchant/orders/{$order->id}/accept")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_a_cancelled_order_says_so_rather_than_failing_vaguely(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());
        $order = $this->order($user->merchant, OrderStatus::Cancelled);

        $this->postJson("/api/v1/merchant/orders/{$order->id}/accept")
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'This order was cancelled.');
    }

    public function test_rejecting_needs_a_real_reason_and_charges_the_customer_nothing(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());
        $order = $this->order($user->merchant);

        $this->postJson("/api/v1/merchant/orders/{$order->id}/reject", ['reason' => 'no'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/merchant/orders/{$order->id}/reject", [
            'reason' => 'Kitchen closed early tonight, sorry.',
        ])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Rejected, $order->status);
        $this->assertSame('merchant', $order->cancelled_by);
        $this->assertSame('Kitchen closed early tonight, sorry.', $order->cancellation_reason);
        // The customer did nothing wrong; a merchant's decision costs them nothing.
        $this->assertSame('0.00', $order->cancellation_fee);
    }

    public function test_preparing_only_follows_acceptance(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());
        $order = $this->order($user->merchant);

        $this->postJson("/api/v1/merchant/orders/{$order->id}/preparing")->assertStatus(422);

        $this->postJson("/api/v1/merchant/orders/{$order->id}/accept")->assertOk();
        $this->postJson("/api/v1/merchant/orders/{$order->id}/preparing")
            ->assertOk()
            ->assertJsonPath('data.status', 'preparing');
    }

    public function test_ready_can_be_reached_from_accepted_or_preparing(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());

        // A small kitchen that plates immediately skips the preparing tap.
        $straight = $this->order($user->merchant, OrderStatus::Accepted);
        $this->postJson("/api/v1/merchant/orders/{$straight->id}/ready")->assertOk();
        $this->assertNotNull($straight->fresh()->ready_at);

        $viaPreparing = $this->order($user->merchant, OrderStatus::Preparing);
        $this->postJson("/api/v1/merchant/orders/{$viaPreparing->id}/ready")->assertOk();
        $this->assertSame(OrderStatus::ReadyForPickup, $viaPreparing->fresh()->status);
    }

    public function test_a_merchant_cannot_mark_an_order_delivered(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());
        $order = $this->order($user->merchant, OrderStatus::ReadyForPickup);

        // Delivery is the rider's to confirm — there is no merchant route for it.
        $this->postJson("/api/v1/merchant/orders/{$order->id}/delivered")->assertNotFound();
        $this->postJson("/api/v1/merchant/orders/{$order->id}/ready")->assertStatus(422);
    }

    public function test_history_returns_finished_orders_newest_first(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());
        $merchant = $user->merchant;

        $this->order($merchant);
        $delivered = $this->order($merchant, OrderStatus::Delivered);

        $response = $this->getJson('/api/v1/merchant/orders?history=1')->assertOk();

        $this->assertSame([$delivered->id], array_column($response->json('data'), 'id'));
    }

    public function test_the_portal_shows_the_queue_and_accepts_an_order(): void
    {
        $user = $this->merchantUser();
        $order = $this->order($user->merchant);

        $this->actingAs($user)->get('/merchants/orders')
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('Chicken 65');

        $this->actingAs($user)->post("/merchants/orders/{$order->id}/accept", ['prep_minutes' => 20])
            ->assertRedirect();

        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
    }

    public function test_the_portal_refuses_another_merchants_order(): void
    {
        $victim = $this->merchantUser();
        $order = $this->order($victim->merchant);

        $this->actingAs($this->merchantUser())
            ->get("/merchants/orders/{$order->id}")
            ->assertNotFound();
    }

    public function test_order_endpoints_reject_anonymous_callers(): void
    {
        $this->getJson('/api/v1/merchant/orders')->assertUnauthorized();
    }

    public function test_the_handover_panel_appears_once_the_food_is_ready(): void
    {
        $user = $this->merchantUser();
        $waiting = $this->order($user->merchant, OrderStatus::ReadyForPickup);
        $waiting->update(['pickup_code' => '4821']);

        $page = $this->actingAs($user)->get("/merchants/orders/{$waiting->id}")->assertOk();

        // The code is the proof the right rider took the right order.
        $page->assertSee('4821')->assertSee('Waiting for a rider');
    }

    public function test_the_handover_panel_names_the_rider_once_assigned(): void
    {
        $user = $this->merchantUser();
        $riderUser = $this->user(UserRole::Rider);
        $rider = $riderUser->rider()->create([
            'full_name' => 'Selvam K',
            'vehicle_type' => 'motorcycle',
            'vehicle_number' => 'TN59AB1234',
        ]);

        $order = $this->order($user->merchant, OrderStatus::PickedUp);
        $order->update(['rider_id' => $rider->id, 'picked_up_at' => now()]);

        $this->actingAs($user)->get("/merchants/orders/{$order->id}")
            ->assertOk()
            ->assertSee('Selvam K')
            ->assertSee('TN59AB1234')
            ->assertSee($riderUser->phone);
    }

    public function test_the_handover_panel_is_hidden_before_the_food_is_ready(): void
    {
        $user = $this->merchantUser();
        $order = $this->order($user->merchant, OrderStatus::Preparing);
        $order->update(['pickup_code' => '9137']);

        // Showing the code while the kitchen is still cooking invites a rider
        // to take an order that is not made yet.
        $this->actingAs($user)->get("/merchants/orders/{$order->id}")
            ->assertOk()
            ->assertDontSee('9137');
    }

    public function test_the_demo_order_command_builds_a_working_order(): void
    {
        $merchant = $this->merchantUser()->merchant;

        $this->artisan('nexmile:demo-order', ['--merchant' => $merchant->id])
            ->assertSuccessful();

        $order = $merchant->orders()->sole();
        $this->assertSame(OrderStatus::Placed, $order->status);
        $this->assertTrue($order->items()->exists());
        $this->assertGreaterThan(0, (float) $order->grand_total);
    }

    public function test_the_demo_order_command_refuses_production_without_force(): void
    {
        $merchant = $this->merchantUser()->merchant;
        app()['env'] = 'production';

        $this->artisan('nexmile:demo-order', ['--merchant' => $merchant->id])
            ->expectsOutputToContain('Refusing to run on production')
            ->assertFailed();

        $this->assertSame(0, $merchant->orders()->count());
    }

    public function test_cleaning_removes_demo_orders_and_leaves_real_ones(): void
    {
        $merchant = $this->merchantUser()->merchant;

        $this->artisan('nexmile:demo-order', ['--merchant' => $merchant->id])->assertSuccessful();
        $real = $this->order($merchant);

        $this->artisan('nexmile:demo-order', ['--clean' => true])->assertSuccessful();

        // Hard deleted: a soft-deleted fiction still waits to confuse a payout
        // query that forgets the global scope.
        $this->assertSame(0, Order::withTrashed()->where('order_number', 'like', 'NXD%')->count());
        $this->assertNotNull(Order::find($real->id));
    }

    public function test_the_actions_panel_is_hidden_when_there_is_nothing_to_press(): void
    {
        /*
         * Once a rider holds the order the kitchen has no move left, and the
         * panel rendered anyway as an empty bordered box that read as a fault.
         */
        $user = $this->merchantUser();
        $actionable = $this->order($user->merchant, OrderStatus::Preparing);
        $handedOver = $this->order($user->merchant, OrderStatus::PickedUp);

        $this->actingAs($user)->get("/merchants/orders/{$actionable->id}")
            ->assertOk()
            ->assertSee(route('merchants.orders.ready', $actionable->id));

        $html = $this->actingAs($user)->get("/merchants/orders/{$handedOver->id}")
            ->assertOk()->getContent();

        foreach (['accept', 'preparing', 'ready', 'reject', 'cancel'] as $action) {
            $this->assertStringNotContainsString(
                route("merchants.orders.{$action}", $handedOver->id),
                $html,
                "{$action} is still offered after the rider collected the order",
            );
        }
    }

    public function test_a_rider_without_a_phone_number_says_so(): void
    {
        /*
         * users.phone is nullable, so this happens. A dash in the phone row is
         * useless once the food has left the kitchen — the merchant needs to
         * know they cannot reach the rider, not wonder if it failed to load.
         */
        $user = $this->merchantUser();
        $order = $this->order($user->merchant, OrderStatus::PickedUp);

        $riderUser = $this->user(UserRole::Rider);
        $riderUser->forceFill(['phone' => null])->save();

        $rider = $riderUser->rider()->create([
            'full_name' => 'Selvam K',
            'vehicle_type' => 'motorcycle',
            'vehicle_number' => 'TN59AB1234',
            'kyc_status' => KycStatus::Verified,
        ]);

        $order->forceFill(['rider_id' => $rider->id])->save();

        $this->actingAs($user)->get("/merchants/orders/{$order->id}")
            ->assertOk()
            ->assertSee(__('portal.orders.rider_no_phone'));
    }

    /*
     * The detail page polls this. Everything after "ready for pickup" is done
     * by a rider, so without it a merchant sees nothing until they navigate
     * away and back — which is exactly what they were doing.
     */
    public function test_the_status_endpoint_reports_the_current_status(): void
    {
        $user = $this->merchantUser();
        $order = $this->order($user->merchant, OrderStatus::ReadyForPickup);

        $this->actingAs($user)
            ->getJson("/merchants/orders/{$order->id}/status")
            ->assertOk()
            ->assertJson(['status' => OrderStatus::ReadyForPickup->value, 'rider_id' => null]);
    }

    public function test_the_status_endpoint_is_scoped_to_the_owning_merchant(): void
    {
        // Otherwise it is an order-status oracle for anyone with an id.
        $order = $this->order($this->merchantUser()->merchant);

        $this->actingAs($this->merchantUser())
            ->getJson("/merchants/orders/{$order->id}/status")
            ->assertNotFound();
    }

    public function test_the_detail_page_polls_while_an_order_is_live_and_stops_when_it_is_not(): void
    {
        $user = $this->merchantUser();
        $live = $this->order($user->merchant, OrderStatus::ReadyForPickup);
        $done = $this->order($user->merchant, OrderStatus::Delivered);

        // The URL reaches the page through @json, which escapes the slashes —
        // so match what is actually rendered, not what route() returns.
        $url = fn (Order $o) => trim(json_encode(route('merchants.orders.status', $o->id)), '"');

        $this->actingAs($user)->get("/merchants/orders/{$live->id}")
            ->assertOk()
            ->assertSee($url($live), false);

        // A delivered order is never going to change again; polling it forever
        // is a request every ten seconds for nothing.
        $this->actingAs($user)->get("/merchants/orders/{$done->id}")
            ->assertOk()
            ->assertDontSee($url($done), false);
    }
}
