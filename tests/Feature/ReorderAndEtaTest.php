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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Order again, and the delivery window on a restaurant card.
 *
 * The reorder tests are mostly about what is *not* silently dropped. A basket
 * that comes back one dish short without saying so is a customer paying for an
 * order they did not mean to place, which is a worse failure than refusing the
 * whole thing.
 */
class ReorderAndEtaTest extends CartTest
{
    private function address(User $user): Address
    {
        return $user->addresses()->create([
            'label' => 'home',
            'contact_name' => 'Meena',
            'contact_phone' => '9876543210',
            'line1' => '4 Gandhi Nagar',
            'city' => 'Madurai',
            'pincode' => '625020',
            'latitude' => 9.9200,
            'longitude' => 78.1195,
        ]);
    }

    /**
     * Place a real order through checkout, so the lines and their options are
     * snapshotted exactly as production snapshots them.
     */
    private function placeOrder(Merchant $shop, User $customer, int $quantity = 2): Order
    {
        $dish = $this->dish($shop);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $dish->id,
            'quantity' => $quantity,
        ])->assertCreated();

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery',
            'payment_method' => 'cod',
            'address_id' => $this->address($customer)->id,
        ])->assertCreated();

        return Order::latest('id')->first();
    }

    private function reorder(Order $order): TestResponse
    {
        return $this->postJson("/api/v1/orders/{$order->id}/reorder");
    }

    /*
    |--------------------------------------------------------------------------
    | Order again
    |--------------------------------------------------------------------------
    */

    public function test_the_same_basket_comes_back(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $shop = $this->restaurant();
        $order = $this->placeOrder($shop, $customer, 3);

        $body = $this->reorder($order)->assertOk()->json();

        $this->assertSame([], $body['skipped']);
        $this->assertSame(3, $body['data']['item_count']);
        $this->assertSame('Chicken Biryani', $body['data']['items'][0]['name']);

        // The same shape as GET /restaurants/{id}/cart, so the app has one
        // cart parser rather than two.
        $this->assertArrayHasKey('totals', $body['data']);
        $this->assertArrayHasKey('can_checkout', $body['data']);
    }

    public function test_reordering_replaces_the_basket_rather_than_adding_to_it(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $shop = $this->restaurant();
        $order = $this->placeOrder($shop, $customer, 2);

        // Something left in the basket from another visit.
        $other = $this->dish($shop, ['name' => 'Payasam', 'price' => 60]);
        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $other->id,
            'quantity' => 4,
        ])->assertCreated();

        $body = $this->reorder($order)->assertOk()->json('data');

        /*
         * "Order again" means that order. Merging it into yesterday's
         * leftovers produces a third basket the customer never asked for.
         */
        $this->assertSame(2, $body['item_count']);
        $this->assertSame(['Chicken Biryani'], array_column($body['items'], 'name'));
    }

    public function test_an_out_of_stock_dish_is_named_not_dropped(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $shop = $this->restaurant();
        $order = $this->placeOrder($shop, $customer);

        $shop->menuItems()->first()->update(['is_available' => false]);

        $body = $this->reorder($order)->assertOk()->json();

        $this->assertSame([['name' => 'Chicken Biryani']], $body['skipped']);
        $this->assertSame(0, $body['data']['item_count']);
    }

    public function test_a_deleted_dish_is_named_from_the_order_snapshot(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $shop = $this->restaurant();
        $order = $this->placeOrder($shop, $customer);

        $shop->menuItems()->first()->delete();

        /*
         * The menu row is gone but the order still says what it was. The name
         * comes off the snapshot, so the customer is told which dish is
         * missing rather than how many are.
         */
        $body = $this->reorder($order)->assertOk()->json();

        $this->assertSame([['name' => 'Chicken Biryani']], $body['skipped']);
    }

    public function test_what_survives_is_added_and_what_does_not_is_listed(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $shop = $this->restaurant();

        $keeps = $this->dish($shop, ['name' => 'Parotta', 'price' => 20]);
        $goes = $this->dish($shop, ['name' => 'Mutton Biryani', 'price' => 320]);

        foreach ([$keeps, $goes] as $dish) {
            $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
                'menu_item_id' => $dish->id, 'quantity' => 1,
            ])->assertCreated();
        }

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery',
            'payment_method' => 'cod',
            'address_id' => $this->address($customer)->id,
        ])->assertCreated();

        $order = Order::latest('id')->first();
        $goes->update(['is_available' => false]);

        $body = $this->reorder($order)->assertOk()->json();

        $this->assertSame(['Parotta'], array_column($body['data']['items'], 'name'));
        $this->assertSame([['name' => 'Mutton Biryani']], $body['skipped']);
    }

    public function test_the_chosen_options_come_back_too(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $shop = $this->restaurant();
        $dish = $this->dish($shop);
        $group = $this->spiceGroup($dish);
        $option = $group->options()->first();

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $dish->id,
            'quantity' => 1,
            'option_ids' => [$option->id],
        ])->assertCreated();

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery',
            'payment_method' => 'cod',
            'address_id' => $this->address($customer)->id,
        ])->assertCreated();

        $order = Order::latest('id')->first();

        $body = $this->reorder($order)->assertOk()->json();

        $this->assertSame([], $body['skipped']);
        $this->assertSame(1, $body['data']['item_count']);
    }

    public function test_another_customers_order_cannot_be_reordered(): void
    {
        Sanctum::actingAs($owner = $this->customer());
        $shop = $this->restaurant();
        $theirs = $this->placeOrder($shop, $owner);

        Sanctum::actingAs($this->customer());

        // 404, not 403: a 403 confirms the order exists.
        $this->reorder($theirs)->assertStatus(404);
    }

    public function test_reordering_needs_a_signed_in_customer(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $order = $this->placeOrder($this->restaurant(), $customer);

        app('auth')->forgetGuards();

        $this->postJson("/api/v1/orders/{$order->id}/reorder")->assertStatus(401);
    }

    public function test_an_order_item_carries_the_option_ids_for_an_app_that_rebuilds_it_itself(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $shop = $this->restaurant();
        $dish = $this->dish($shop);
        $group = $this->spiceGroup($dish);
        $option = $group->options()->first();

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $dish->id,
            'quantity' => 1,
            'option_ids' => [$option->id],
        ])->assertCreated();

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery',
            'payment_method' => 'cod',
            'address_id' => $this->address($customer)->id,
        ])->assertCreated();

        $order = Order::latest('id')->first();

        $line = $this->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->json('data.items.0');

        $this->assertSame($dish->id, $line['menu_item_id']);
        $this->assertSame($option->id, $line['options'][0]['item_option_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | The delivery window on a card
    |--------------------------------------------------------------------------
    */

    public function test_a_card_quotes_cooking_plus_the_ride(): void
    {
        config([
            'tracking.average_speed_kmh' => 18,
            'discovery.eta.spread_minutes' => 5,
            'discovery.eta.floor_minutes' => 1,
        ]);

        Sanctum::actingAs($this->customer());
        $this->restaurant(['avg_prep_time_minutes' => 20]);

        $card = $this->getJson('/api/v1/restaurants?latitude=9.9200&longitude=78.1195')
            ->assertOk()
            ->json('data.0');

        // Prep plus the ride, and a band rather than a single number.
        $this->assertGreaterThanOrEqual(20, $card['eta_min_minutes']);
        $this->assertSame($card['eta_min_minutes'] + 5, $card['eta_max_minutes']);
    }

    public function test_nothing_is_promised_faster_than_the_floor(): void
    {
        config([
            'discovery.eta.floor_minutes' => 15,
            'discovery.eta.spread_minutes' => 5,
        ]);

        Sanctum::actingAs($this->customer());

        // Next door, and cooking in two minutes.
        $this->restaurant(['avg_prep_time_minutes' => 2, 'latitude' => 9.9200, 'longitude' => 78.1195]);

        $card = $this->getJson('/api/v1/restaurants?latitude=9.9200&longitude=78.1195')
            ->assertOk()
            ->json('data.0');

        /*
         * A rider still has to be found, reach the counter, be handed a bag
         * and park at the other end. "4-9 min" is a complaint written in
         * advance.
         */
        $this->assertSame(15, $card['eta_min_minutes']);
        $this->assertSame(20, $card['eta_max_minutes']);
    }

    public function test_a_storefront_with_no_customer_position_omits_the_window(): void
    {
        Sanctum::actingAs($this->customer());
        $shop = $this->restaurant();

        $body = $this->getJson("/api/v1/restaurants/{$shop->id}")->assertOk()->json('data');

        /*
         * Absent, not null — there is no distance in this request to measure.
         * Same rule as road_distance_metres: show it when it is there, fall
         * back to avg_prep_time_minutes when it is not.
         */
        $this->assertArrayNotHasKey('eta_min_minutes', $body);
        $this->assertArrayHasKey('avg_prep_time_minutes', $body);
    }

    public function test_the_card_still_carries_its_offers(): void
    {
        Sanctum::actingAs($this->customer());
        $this->restaurant();

        $card = $this->getJson('/api/v1/restaurants?latitude=9.9200&longitude=78.1195')
            ->assertOk()
            ->json('data.0');

        // Already built before it was asked for; this pins the shape the app
        // reads: a list, first one shown, label printed as sent.
        $this->assertIsArray($card['offers']);

        foreach ($card['offers'] as $offer) {
            $this->assertArrayHasKey('label', $offer);
            $this->assertArrayHasKey('type', $offer);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The rider on the tracking screen
    |--------------------------------------------------------------------------
    */

    public function test_tracking_sends_the_riders_rating_and_a_null_photo(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $shop = $this->restaurant();
        $order = $this->placeOrder($shop, $customer);

        $order->forceFill(['status' => OrderStatus::RiderAssigned->value])->save();

        $rider = $this->reorderRider();
        $order->forceFill(['rider_id' => $rider->id, 'assigned_at' => now()])->save();

        $block = $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->json('data.rider');

        $this->assertSame('Selvam K', $block['name']);
        $this->assertSame(4.8, $block['rating']);

        /*
         * Sent as null rather than omitted, so the app can ship the layout
         * now and light up when there is a photo. Riders have no photo column
         * yet, and that is a decision about consent, not a migration.
         */
        $this->assertArrayHasKey('photo_url', $block);
        $this->assertNull($block['photo_url']);
    }

    public function test_a_new_rider_has_no_rating_rather_than_zero(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $shop = $this->restaurant();
        $order = $this->placeOrder($shop, $customer);

        $order->forceFill(['status' => OrderStatus::RiderAssigned->value])->save();

        $rider = $this->reorderRider(['rating' => null, 'rating_count' => 0]);
        $order->forceFill(['rider_id' => $rider->id, 'assigned_at' => now()])->save();

        $block = $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->json('data.rider');

        // "0.0" beside a stranger's name at the door reads as a warning where
        // a new rider is only new.
        $this->assertNull($block['rating']);
        $this->assertSame(0, $block['rating_count']);
    }

    /** @param array<string, mixed> $attributes */
    private function reorderRider(array $attributes = []): Rider
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'name' => 'Rider '.$n,
            'phone' => '97940000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "reorderrider{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Rider,
            'status' => UserStatus::Active,
        ]);

        return $user->rider()->create([
            'full_name' => 'Selvam K',
            'vehicle_type' => 'motorcycle',
            'vehicle_number' => 'TN59EF'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'kyc_status' => KycStatus::Verified,
            'kyc_verified_at' => now(),
            'driving_licence_expiry' => now()->addYear(),
            'insurance_expiry' => now()->addYear(),
            'duty_status' => RiderStatus::Available,
            'rating' => 4.8,
            'rating_count' => 32,
            ...$attributes,
        ]);
    }
}
