<?php

namespace Tests\Feature;

use App\Enums\FulfilmentType;
use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\MenuItem;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Which dish an order line was, as distinct from which line it is.
 *
 * Dish ratings are keyed by menu item. Without menu_item_id on the line the
 * app has nothing to attach a star to, and guessing from the line id would
 * rate whichever dish happens to share that number.
 */
class OrderItemIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'Customer '.$n,
            'phone' => '95000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "lineid{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Customer,
            'status' => UserStatus::Active,
        ]);
    }

    private function restaurant(): Merchant
    {
        static $n = 0;
        $n++;

        $owner = User::create([
            'name' => 'Owner '.$n,
            'phone' => '95100000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "lineidshop{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        return Merchant::create([
            'user_id' => $owner->id,
            'business_name' => 'Ponnusamy Hotel',
            'owner_name' => 'Owner',
            'address_line1' => '9 Anna Salai',
            'city' => 'Madurai',
            'pincode' => '625001',
            'kyc_status' => KycStatus::Verified,
        ]);
    }

    private function order(User $customer, Merchant $merchant, ?MenuItem $dish): Order
    {
        static $n = 0;
        $n++;

        $order = $merchant->orders()->create([
            'order_number' => 'NXL'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'user_id' => $customer->id,
            'status' => OrderStatus::Delivered,
            'fulfilment_type' => FulfilmentType::Delivery,
            'delivery_contact_name' => 'Meena',
            'delivery_line1' => '4 Gandhi Nagar',
            'delivery_city' => 'Madurai',
            'delivery_pincode' => '625020',
            'items_total' => 60,
            'grand_total' => 90,
            'merchant_payout' => 54,
            'placed_at' => now(),
        ]);

        $order->items()->create([
            'menu_item_id' => $dish?->id,
            'name' => $dish?->name ?? 'Masala Dosa',
            'unit_price' => 60,
            'quantity' => 1,
            'line_total' => 60,
        ]);

        return $order->fresh();
    }

    public function test_an_order_line_says_which_dish_it_was(): void
    {
        $customer = $this->customer();
        $merchant = $this->restaurant();
        $dish = $merchant->menuItems()->create(['name' => 'Masala Dosa', 'price' => 60, 'is_available' => true]);

        $order = $this->order($customer, $merchant, $dish);

        Sanctum::actingAs($customer);

        // On the list, which is where the app builds its rating sheet.
        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.0.items.0.menu_item_id', $dish->id);

        // And on the single order.
        $this->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.menu_item_id', $dish->id);
    }

    public function test_the_line_id_and_the_dish_id_are_not_the_same_number(): void
    {
        /*
         * The reason guessing is unsafe. Order line ids and menu item ids are
         * separate sequences that happen to overlap, so a rating keyed off the
         * line id lands on whichever dish shares that number.
         */
        $customer = $this->customer();
        $merchant = $this->restaurant();

        // Push the menu item ids well past where the line ids start.
        foreach (range(1, 5) as $i) {
            $merchant->menuItems()->create(['name' => 'Filler '.$i, 'price' => 10, 'is_available' => true]);
        }

        $dish = $merchant->menuItems()->create(['name' => 'Masala Dosa', 'price' => 60, 'is_available' => true]);
        $order = $this->order($customer, $merchant, $dish);

        Sanctum::actingAs($customer);

        $line = $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data.items.0');

        $this->assertNotSame($line['id'], $line['menu_item_id']);
        $this->assertSame($dish->id, $line['menu_item_id']);
    }

    public function test_a_dish_taken_off_the_menu_can_still_be_rated(): void
    {
        /*
         * Menu items are soft deleted, so the reference survives the dish
         * being withdrawn. That is the behaviour we want: someone who ate it
         * last night should still be able to rate it this morning, even though
         * nobody can order it any more.
         */
        $customer = $this->customer();
        $merchant = $this->restaurant();
        $dish = $merchant->menuItems()->create(['name' => 'Masala Dosa', 'price' => 60, 'is_available' => true]);

        $order = $this->order($customer, $merchant, $dish);
        $dish->delete();

        Sanctum::actingAs($customer);

        $this->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.menu_item_id', $dish->id);
    }

    public function test_a_line_with_no_dish_behind_it_serialises_as_null(): void
    {
        // The column is nullable, so the app has to expect null rather than
        // assume every line is rateable.
        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant(), null);

        Sanctum::actingAs($customer);

        $this->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.name', 'Masala Dosa')
            ->assertJsonPath('data.items.0.menu_item_id', null);
    }
}
