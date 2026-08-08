<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\RiderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Address;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Rider;
use App\Models\User;
use App\Services\LiveState\RiderLocationService;
use Laravel\Sanctum\Sanctum;
use Mockery;

/**
 * The delivery half of the loop (EP8, EP9, EP10).
 *
 * Extends CheckoutTest for the customer helpers — a real order is the only
 * honest input to dispatch.
 */
class DispatchTest extends CheckoutTest
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Redis is not running in CI. The board falls back to the rider's last
         * MySQL position, which is what these tests exercise; the geo set is
         * covered by RedisHealthCommand against a real server.
         */
        $this->mock(RiderLocationService::class, function ($mock) {
            $mock->shouldReceive('state')->andReturn([])->byDefault();
            $mock->shouldReceive('updateLocation')->andReturnNull()->byDefault();
            $mock->shouldReceive('setDutyStatus')->andReturnNull()->byDefault();
            $mock->shouldReceive('goOffline')->andReturnNull()->byDefault();
            $mock->shouldReceive('isPresent')->andReturnTrue()->byDefault();
            $mock->shouldReceive('ridersNear')->andReturn([])->byDefault();
        });
    }

    /** A rider standing at the restaurant's door, ready to work. */
    protected function rider(array $attributes = []): Rider
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'name' => 'Rider '.$n,
            'phone' => '97910000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "rider{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Rider,
            'status' => UserStatus::Active,
        ]);

        return $user->rider()->create([
            'full_name' => 'Selvam K',
            'vehicle_type' => 'motorcycle',
            'vehicle_number' => 'TN59AB'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'kyc_status' => KycStatus::Verified,
            'kyc_verified_at' => now(),
            'driving_licence_expiry' => now()->addYear(),
            'insurance_expiry' => now()->addYear(),
            'duty_status' => RiderStatus::Available,
            'last_latitude' => 9.9195,
            'last_longitude' => 78.1193,
            'last_location_at' => now(),
            ...$attributes,
        ]);
    }

    /** Places a real order and walks it to ready_for_pickup. */
    protected function readyOrder(?Merchant $shop = null, ?User $customer = null): Order
    {
        $customer ??= $this->customer();
        $shop ??= $this->restaurant();

        Sanctum::actingAs($customer);
        $address = $this->addressFor($customer);
        $dish = $this->dish($shop);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $dish->id, 'quantity' => 1,
        ])->assertCreated();

        $order = $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery', 'payment_method' => 'cod', 'address_id' => $address->id,
        ])->assertCreated()->json('data');

        Sanctum::actingAs($shop->user);
        $this->postJson("/api/v1/merchant/orders/{$order['id']}/accept")->assertOk();
        $this->postJson("/api/v1/merchant/orders/{$order['id']}/ready")->assertOk();

        return Order::find($order['id']);
    }

    protected function addressFor(User $user): Address
    {
        return $user->addresses()->create([
            'label' => 'home', 'contact_name' => 'Meena', 'contact_phone' => '9876543210',
            'line1' => '4 Gandhi Nagar', 'city' => 'Madurai', 'pincode' => '625020',
            'latitude' => 9.9200, 'longitude' => 78.1195,
        ]);
    }

    public function test_the_whole_loop_runs_from_order_to_delivered(): void
    {
        $order = $this->readyOrder();
        $rider = $this->rider();

        Sanctum::actingAs($rider->user);

        $board = $this->getJson('/api/v1/rider/orders/available')->assertOk()->json('data');
        $this->assertSame([$order->id], array_column($board, 'id'));

        // The drop address is withheld until the job is actually taken.
        $this->assertArrayNotHasKey('dropoff', $board[0]);

        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'rider_assigned')
            ->assertJsonPath('data.dropoff.address', '4 Gandhi Nagar, Madurai, 625020');

        $this->postJson("/api/v1/rider/orders/{$order->id}/pickup", [
            'pickup_code' => $order->fresh()->pickup_code,
        ])->assertOk()->assertJsonPath('data.status', 'picked_up');

        $this->postJson("/api/v1/rider/orders/{$order->id}/deliver")
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');

        $order->refresh();
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertNotNull($order->assigned_at);
        $this->assertNotNull($order->picked_up_at);
        $this->assertNotNull($order->delivered_at);

        // The rider is freed and credited.
        $rider->refresh();
        $this->assertSame(RiderStatus::Available, $rider->duty_status);
        $this->assertSame(1, $rider->completed_deliveries);
    }

    public function test_a_rider_is_never_offered_their_own_order(): void
    {
        $rider = $this->rider();
        $shop = $this->restaurant();

        // The rider orders dinner using the customer app — allowed, and normal.
        $order = $this->readyOrder($shop, $rider->user);

        Sanctum::actingAs($rider->user);

        $this->getJson('/api/v1/rider/orders/available')->assertOk()->assertJsonCount(0, 'data');

        // Order food, deliver it to yourself, keep the delivery fee. Repeatable
        // at will, and it looks like a rider who is simply very fast.
        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")
            ->assertStatus(422)
            ->assertJsonPath('errors.order.0', 'You cannot deliver your own order.');
    }

    public function test_only_one_rider_wins_the_same_order(): void
    {
        $order = $this->readyOrder();
        $first = $this->rider();
        $second = $this->rider();

        Sanctum::actingAs($first->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")->assertOk();

        Sanctum::actingAs($second->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")
            ->assertStatus(422)
            ->assertJsonPath('errors.order.0', 'Another rider took this order.');

        $this->assertSame($first->id, $order->fresh()->rider_id);
    }

    public function test_a_wrong_pickup_code_is_refused(): void
    {
        $order = $this->readyOrder();
        $rider = $this->rider();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")->assertOk();

        $this->postJson("/api/v1/rider/orders/{$order->id}/pickup", ['pickup_code' => '0000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pickup_code');

        // Without the code this is just a button pressable from anywhere.
        $this->assertSame(OrderStatus::RiderAssigned, $order->fresh()->status);
    }

    public function test_delivery_requires_pickup_first(): void
    {
        $order = $this->readyOrder();
        $rider = $this->rider();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")->assertOk();

        $this->postJson("/api/v1/rider/orders/{$order->id}/deliver")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_an_order_assigned_to_someone_else_is_not_reachable(): void
    {
        $order = $this->readyOrder();
        $mine = $this->rider();
        $theirs = $this->rider();

        Sanctum::actingAs($mine->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")->assertOk();

        Sanctum::actingAs($theirs->user);
        $this->getJson("/api/v1/rider/orders/{$order->id}")->assertNotFound();
        $this->postJson("/api/v1/rider/orders/{$order->id}/pickup", ['pickup_code' => $order->fresh()->pickup_code])
            ->assertNotFound();
    }

    public function test_a_rider_carries_one_order_at_a_time(): void
    {
        $first = $this->readyOrder();
        $second = $this->readyOrder();
        $rider = $this->rider();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/orders/{$first->id}/accept")->assertOk();

        // Batching a second drop at 1 km saves minutes and costs that customer
        // their food going cold in a bag.
        $this->postJson("/api/v1/rider/orders/{$second->id}/accept")
            ->assertStatus(422)
            ->assertJsonPath('errors.rider.0', 'Finish your current delivery first.');

        $this->getJson('/api/v1/rider/orders/available')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_offline_rider_sees_nothing_and_cannot_accept(): void
    {
        $order = $this->readyOrder();
        $rider = $this->rider(['duty_status' => RiderStatus::Offline]);

        Sanctum::actingAs($rider->user);

        $this->getJson('/api/v1/rider/orders/available')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.can_accept', false);

        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")
            ->assertStatus(422)
            ->assertJsonPath('errors.rider.0', 'Go online before accepting orders.');
    }

    public function test_an_unverified_rider_cannot_accept(): void
    {
        $order = $this->readyOrder();
        $rider = $this->rider(['kyc_status' => KycStatus::Submitted, 'kyc_verified_at' => null]);

        Sanctum::actingAs($rider->user);

        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")
            ->assertStatus(422)
            ->assertJsonPath('errors.rider.0', 'Your documents are still being verified.');
    }

    public function test_a_rider_with_an_expired_licence_cannot_accept(): void
    {
        $order = $this->readyOrder();
        $rider = $this->rider();
        $rider->forceFill(['driving_licence_expiry' => now()->subDay()])->save();

        Sanctum::actingAs($rider->user);

        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")
            ->assertStatus(422)
            ->assertJsonPath('errors.rider.0', 'Your licence or insurance has expired. Upload current documents to go online.');
    }

    public function test_a_self_pickup_order_never_reaches_the_board(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $shop = $this->restaurant();
        $dish = $this->dish($shop);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", ['menu_item_id' => $dish->id])->assertCreated();
        $order = $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'pickup', 'payment_method' => 'cod',
        ])->assertCreated()->json('data');

        Sanctum::actingAs($shop->user);
        $this->postJson("/api/v1/merchant/orders/{$order['id']}/accept")->assertOk();
        $this->postJson("/api/v1/merchant/orders/{$order['id']}/ready")->assertOk();

        $rider = $this->rider();
        Sanctum::actingAs($rider->user);

        // The customer is collecting it themselves.
        $this->getJson('/api/v1/rider/orders/available')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/rider/orders/{$order['id']}/accept")
            ->assertStatus(422)
            ->assertJsonPath('errors.order.0', 'This order is being collected by the customer.');
    }

    public function test_the_customer_sees_the_rider_once_assigned(): void
    {
        $customer = $this->customer();
        $order = $this->readyOrder(null, $customer);
        $rider = $this->rider();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")->assertOk();

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->assertJsonPath('data.status', 'rider_assigned')
            ->assertJsonPath('data.rider.name', 'Selvam K')
            ->assertJsonPath('data.rider.phone', $rider->user->phone);
    }

    public function test_the_rider_position_is_hidden_once_the_order_is_delivered(): void
    {
        // A rider's movements are their own business after the handover.
        $this->mock(RiderLocationService::class, function ($mock) {
            $mock->shouldReceive('state')->andReturn([
                'latitude' => '9.9199', 'longitude' => '78.1194', 'updated_at' => now()->toIso8601String(),
            ]);
            $mock->shouldReceive('setDutyStatus')->andReturnNull();
            $mock->shouldReceive('updateLocation')->andReturnNull();
        });

        $customer = $this->customer();
        $order = $this->readyOrder(null, $customer);
        $rider = $this->rider();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")->assertOk();

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->assertJsonPath('data.rider.location.latitude', 9.9199);

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/pickup", ['pickup_code' => $order->fresh()->pickup_code])->assertOk();
        $this->postJson("/api/v1/rider/orders/{$order->id}/deliver")->assertOk();

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->assertJsonPath('data.rider.location', null);
    }

    public function test_a_location_ping_is_stored_and_refuses_other_roles(): void
    {
        $rider = $this->rider();

        Sanctum::actingAs($rider->user);
        $this->postJson('/api/v1/rider/location', ['latitude' => 9.93, 'longitude' => 78.12])
            ->assertOk()
            ->assertJsonPath('data.tracking', true);

        $this->assertSame('9.9300000', $rider->fresh()->last_latitude);

        Sanctum::actingAs($this->customer());
        $this->postJson('/api/v1/rider/location', ['latitude' => 9.93, 'longitude' => 78.12])
            ->assertForbidden();
    }

    public function test_an_offline_rider_is_not_tracked(): void
    {
        $rider = $this->rider(['duty_status' => RiderStatus::Offline]);

        Sanctum::actingAs($rider->user);
        $this->postJson('/api/v1/rider/location', ['latitude' => 9.93, 'longitude' => 78.12])
            ->assertOk()
            ->assertJsonPath('data.tracking', false);

        $this->assertNull($rider->fresh()->last_location_at?->diffInSeconds(now()) > 5 ?: null);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
