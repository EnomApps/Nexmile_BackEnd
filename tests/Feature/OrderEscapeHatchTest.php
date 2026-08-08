<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\RiderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Ways out of an order that has gone wrong.
 *
 * Every one of these existed as a hole rather than a feature: without them a
 * kitchen with a gas failure, a rider with a puncture and a support agent
 * taking a phone call all had nothing to do but wait.
 */
class OrderEscapeHatchTest extends DispatchTest
{
    private function admin(): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'Admin '.$n,
            'phone' => '97950000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "opsadmin{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);
    }

    public function test_a_rider_carrying_an_order_cannot_clock_off(): void
    {
        $order = $this->readyOrder();
        $rider = $this->rider();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")->assertOk();

        // The order would keep their rider_id and sit in flight with nobody
        // accountable for food already in a bag.
        $this->postJson('/api/v1/rider/duty-status', ['duty_status' => 'offline'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Finish your delivery first, or hand it back before you have collected it.');

        $this->assertSame(RiderStatus::OnOrder, $rider->fresh()->duty_status);
    }

    public function test_a_rider_can_hand_back_an_order_before_collecting_it(): void
    {
        $order = $this->readyOrder();
        $rider = $this->rider();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")->assertOk();

        $this->postJson("/api/v1/rider/orders/{$order->id}/release", ['reason' => 'Puncture'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_for_pickup');

        $order->refresh();
        $this->assertNull($order->rider_id);
        $this->assertSame(OrderStatus::ReadyForPickup, $order->status);
        $this->assertSame(RiderStatus::Available, $rider->fresh()->duty_status);

        // And it is genuinely back on the board for someone else.
        $other = $this->rider();
        Sanctum::actingAs($other->user);
        $this->getJson('/api/v1/rider/orders/available')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_food_already_collected_cannot_be_handed_back(): void
    {
        $order = $this->readyOrder();
        $rider = $this->rider();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")->assertOk();
        $this->postJson("/api/v1/rider/orders/{$order->id}/pickup", ['pickup_code' => $order->fresh()->pickup_code])->assertOk();

        // It cannot go back on a board — a human has to get involved.
        $this->postJson("/api/v1/rider/orders/{$order->id}/release")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(OrderStatus::PickedUp, $order->fresh()->status);
    }

    public function test_a_merchant_can_cancel_after_accepting(): void
    {
        $order = $this->readyOrder();

        Sanctum::actingAs($order->merchant->user);

        $this->postJson("/api/v1/merchant/orders/{$order->id}/cancel", ['reason' => 'no'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/merchant/orders/{$order->id}/cancel", [
            'reason' => 'Gas cylinder ran out, sorry.',
        ])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('merchant', $order->cancelled_by);
        $this->assertSame('Gas cylinder ran out, sorry.', $order->cancellation_reason);
    }

    public function test_a_merchant_cannot_cancel_once_a_rider_is_collecting(): void
    {
        $order = $this->readyOrder();
        $rider = $this->rider();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")->assertOk();

        Sanctum::actingAs($order->merchant->user);
        $this->postJson("/api/v1/merchant/orders/{$order->id}/cancel", ['reason' => 'Changed our mind entirely.'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'A rider is already collecting this order. Call them before cancelling.');
    }

    public function test_admin_can_look_up_an_order_and_see_who_did_what(): void
    {
        $order = $this->readyOrder();

        $this->actingAs($this->admin())
            ->get('/admin/orders?search='.$order->order_number)
            ->assertOk()
            ->assertSee($order->order_number);

        // When a customer rings, the first question is what happened to this
        // order — and until now there was no way to answer it.
        $this->actingAs($this->admin())
            ->get("/admin/orders/{$order->id}")
            ->assertOk()
            ->assertSee($order->merchant->business_name)
            ->assertSee('Timeline');
    }

    public function test_an_order_nobody_took_shows_up_as_needing_attention(): void
    {
        $fresh = $this->readyOrder();
        $stale = $this->readyOrder();
        $stale->forceFill(['ready_at' => now()->subMinutes(30)])->save();

        $page = $this->actingAs($this->admin())->get('/admin/orders?view=stale')->assertOk();

        $page->assertSee($stale->order_number)->assertDontSee($fresh->order_number);
    }

    public function test_admin_can_cancel_a_stuck_order(): void
    {
        $order = $this->readyOrder();

        $this->actingAs($this->admin())
            ->post("/admin/orders/{$order->id}/cancel", ['reason' => 'No rider available tonight.'])
            ->assertRedirect();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('admin', $order->cancelled_by);
    }

    public function test_admin_order_pages_are_closed_to_everyone_else(): void
    {
        $order = $this->readyOrder();

        foreach ([$this->customer(), $order->merchant->user] as $user) {
            $this->actingAs($user)->get('/admin/orders')->assertForbidden();
            $this->actingAs($user)->post("/admin/orders/{$order->id}/cancel", ['reason' => 'Trying it on.'])
                ->assertForbidden();
        }

        $this->assertSame(OrderStatus::ReadyForPickup, $order->fresh()->status);
    }
}
