<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\MenuItem;
use App\Models\Merchant;
use App\Models\Order;
use Laravel\Sanctum\Sanctum;

/**
 * The BESTSELLER badge.
 *
 * Most of these tests are about when the badge should *not* appear. A badge
 * that shows up on everything says nothing, and one that shows up on a dish
 * nobody has bought teaches customers to ignore every badge on the platform.
 */
class BestsellerTest extends CartTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // Spelled out rather than inherited, so a later change to the shipped
        // defaults cannot quietly rewrite what these tests are asserting.
        config([
            'menu.bestsellers.per_merchant' => 2,
            'menu.bestsellers.window_days' => 30,
            'menu.bestsellers.min_orders' => 5,
        ]);
    }

    /**
     * Sell a dish, without going through checkout.
     *
     * Checkout would be the fuller test, but it would also mean a cart, an
     * address and a payment per dish — several hundred lines to arrive at the
     * same two columns the command actually reads.
     */
    private function sell(Merchant $shop, MenuItem $dish, int $quantity, OrderStatus $status = OrderStatus::Delivered, ?int $daysAgo = null): Order
    {
        $order = Order::create([
            'order_number' => 'NX'.str_pad((string) (Order::count() + 1), 6, '0', STR_PAD_LEFT),
            'user_id' => $this->customer()->id,
            'merchant_id' => $shop->id,
            'status' => $status,
            'fulfilment_type' => 'delivery',
            'payment_method' => 'cod',
            'subtotal' => $dish->price * $quantity,
            'grand_total' => $dish->price * $quantity,
        ]);

        if ($daysAgo !== null) {
            $order->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
        }

        $order->items()->create([
            'menu_item_id' => $dish->id,
            'name' => $dish->name,
            'unit_price' => $dish->price,
            'quantity' => $quantity,
            'line_total' => $dish->price * $quantity,
        ]);

        return $order;
    }

    private function recompute(array $options = []): void
    {
        $this->artisan('nexmile:mark-bestsellers', $options)->assertSuccessful();
    }

    /*
    |--------------------------------------------------------------------------
    | What earns the badge
    |--------------------------------------------------------------------------
    */

    public function test_the_best_selling_dishes_are_badged(): void
    {
        $shop = $this->restaurant();
        $biryani = $this->dish($shop, ['name' => 'Chicken Biryani']);
        $parotta = $this->dish($shop, ['name' => 'Parotta']);
        $payasam = $this->dish($shop, ['name' => 'Payasam']);

        $this->sell($shop, $biryani, 20);
        $this->sell($shop, $parotta, 12);
        $this->sell($shop, $payasam, 6);

        $this->recompute();

        // per_merchant is 2, so the third best seller misses out even though
        // it cleared the minimum.
        $this->assertTrue($biryani->fresh()->is_bestseller);
        $this->assertTrue($parotta->fresh()->is_bestseller);
        $this->assertFalse($payasam->fresh()->is_bestseller);
    }

    public function test_quantity_counts_not_order_count(): void
    {
        $shop = $this->restaurant();
        $idli = $this->dish($shop, ['name' => 'Idli']);
        $vadai = $this->dish($shop, ['name' => 'Vadai']);

        // One bill, eight plates — a dish people order for the whole table.
        $this->sell($shop, $idli, 8);

        // Six separate bills, one each.
        foreach (range(1, 6) as $ignored) {
            $this->sell($shop, $vadai, 1);
        }

        $this->recompute();

        $this->assertTrue($idli->fresh()->is_bestseller);
        $this->assertTrue($vadai->fresh()->is_bestseller);
    }

    /*
    |--------------------------------------------------------------------------
    | What does not
    |--------------------------------------------------------------------------
    */

    public function test_a_restaurant_with_too_little_history_has_no_bestsellers(): void
    {
        $shop = $this->restaurant();
        $dish = $this->dish($shop);

        // Four sold, minimum is five.
        $this->sell($shop, $dish, 4);

        $this->recompute();

        $this->assertFalse($dish->fresh()->is_bestseller);
    }

    public function test_rejected_and_abandoned_orders_do_not_count(): void
    {
        $shop = $this->restaurant();
        $unlucky = $this->dish($shop, ['name' => 'Mutton Chukka']);

        /*
         * The dish the kitchen keeps running out of would otherwise top the
         * list — rejections are evidence of demand, not of a dish worth
         * recommending to somebody who will be told no.
         */
        $this->sell($shop, $unlucky, 30, OrderStatus::Rejected);
        $this->sell($shop, $unlucky, 30, OrderStatus::PendingPayment);
        $this->sell($shop, $unlucky, 30, OrderStatus::Cancelled);

        $this->recompute();

        $this->assertFalse($unlucky->fresh()->is_bestseller);
    }

    public function test_sales_outside_the_window_do_not_count(): void
    {
        $shop = $this->restaurant();
        $dish = $this->dish($shop, ['name' => 'Pongal']);

        $this->sell($shop, $dish, 40, OrderStatus::Delivered, daysAgo: 45);

        $this->recompute();

        $this->assertFalse($dish->fresh()->is_bestseller);
    }

    public function test_a_dish_that_stops_selling_loses_the_badge(): void
    {
        $shop = $this->restaurant();
        $dish = $this->dish($shop, ['name' => 'Kothu Parotta']);
        $dish->forceFill(['is_bestseller' => true])->save();

        // Nothing sold inside the window.
        $this->recompute();

        $this->assertFalse($dish->fresh()->is_bestseller);
    }

    public function test_one_restaurants_sales_do_not_badge_anothers_menu(): void
    {
        $mine = $this->restaurant();
        $theirs = $this->restaurant();

        $mineDish = $this->dish($mine, ['name' => 'Chicken 65']);
        $theirsDish = $this->dish($theirs, ['name' => 'Chicken 65']);

        $this->sell($mine, $mineDish, 25);

        $this->recompute();

        $this->assertTrue($mineDish->fresh()->is_bestseller);
        $this->assertFalse($theirsDish->fresh()->is_bestseller);
    }

    /*
    |--------------------------------------------------------------------------
    | The command itself
    |--------------------------------------------------------------------------
    */

    public function test_a_dry_run_writes_nothing(): void
    {
        $shop = $this->restaurant();
        $dish = $this->dish($shop);
        $this->sell($shop, $dish, 20);

        $this->recompute(['--dry-run' => true]);

        $this->assertFalse($dish->fresh()->is_bestseller);
    }

    public function test_a_single_restaurant_can_be_recomputed_alone(): void
    {
        $mine = $this->restaurant();
        $theirs = $this->restaurant();

        $mineDish = $this->dish($mine);
        $theirsDish = $this->dish($theirs);

        $this->sell($mine, $mineDish, 20);
        $this->sell($theirs, $theirsDish, 20);

        $this->recompute(['--merchant' => $mine->id]);

        $this->assertTrue($mineDish->fresh()->is_bestseller);
        $this->assertFalse($theirsDish->fresh()->is_bestseller);
    }

    public function test_a_nonsense_window_is_refused_rather_than_badging_everything(): void
    {
        config(['menu.bestsellers.window_days' => 0]);

        $this->artisan('nexmile:mark-bestsellers')->assertFailed();
    }

    /*
    |--------------------------------------------------------------------------
    | What the app sees
    |--------------------------------------------------------------------------
    */

    public function test_the_menu_tells_the_app_which_dishes_are_bestsellers(): void
    {
        $shop = $this->restaurant();
        $mains = $shop->categories()->create(['name' => 'Mains', 'sort_order' => 0]);

        $popular = $this->dish($shop, ['name' => 'Chicken Biryani', 'category_id' => $mains->id]);
        $quiet = $this->dish($shop, ['name' => 'Payasam', 'category_id' => $mains->id, 'sort_order' => 1]);

        $this->sell($shop, $popular, 20);
        $this->recompute();

        Sanctum::actingAs($this->customer());

        $items = $this->getJson("/api/v1/restaurants/{$shop->id}/menu")
            ->assertOk()
            ->json('data.menu.0.items');

        $flags = collect($items)->pluck('is_bestseller', 'name');

        $this->assertTrue($flags['Chicken Biryani']);

        // Sent as false rather than omitted, so the app never has to tell
        // "not a bestseller" apart from "this build predates the field".
        $this->assertFalse($flags['Payasam']);
    }

    public function test_the_flag_is_not_something_a_restaurant_can_set_on_itself(): void
    {
        $shop = $this->restaurant();
        $dish = $this->dish($shop);

        /*
         * A badge the seller awards itself is an advertisement. Keeping the
         * column out of $fillable means neither the merchant portal nor the
         * merchant API can write it however the payload is shaped — the
         * command updates it through the query builder instead.
         */
        $dish->update(['is_bestseller' => true]);

        $this->assertFalse($dish->fresh()->is_bestseller);
        $this->assertNotContains('is_bestseller', $dish->getFillable());
    }
}
