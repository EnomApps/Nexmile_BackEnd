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
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Whether the fifteen-minute promise is actually kept.
 *
 * Measured from the rider collecting the food, not from the customer tapping
 * Order — the kitchen's time is the kitchen's. Both timestamps have always
 * been stored, so this is a question with an answer rather than an argument
 * about what is achievable.
 */
class DeliveryTimesTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(): Merchant
    {
        $owner = User::create([
            'name' => 'Owner', 'phone' => '9445000111', 'email' => 'dt.owner@example.in',
            'password' => 'secret', 'role' => UserRole::Merchant, 'status' => UserStatus::Active,
        ]);

        return Merchant::create([
            'user_id' => $owner->id,
            'business_name' => 'Ponnusamy Hotel',
            'owner_name' => 'Owner',
            'address_line1' => '9 Anna Salai',
            'city' => 'Madurai',
            'pincode' => '625001',
            'kyc_status' => KycStatus::Verified,
            'is_accepting_orders' => true,
        ]);
    }

    /** A delivered order that took exactly this many minutes from pickup. */
    private function delivery(Merchant $shop, int $minutes, ?int $metres = null): Order
    {
        static $n = 0;
        $n++;

        $customer = User::create([
            'name' => 'Customer '.$n,
            // A prefix of its own: the merchant owner's number is in the same
            // range, and $n runs past it inside one test class.
            'phone' => '9333'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'email' => "dt.cust{$n}@example.in",
            'password' => 'secret', 'role' => UserRole::Customer, 'status' => UserStatus::Active,
        ]);

        $pickedUp = now()->subHours(2);

        $order = $shop->orders()->create([
            'order_number' => 'NXD'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'user_id' => $customer->id,
            'status' => OrderStatus::Delivered,
            'fulfilment_type' => FulfilmentType::Delivery,
            'delivery_contact_name' => 'Meena',
            'delivery_line1' => '4 Gandhi Nagar',
            'delivery_city' => 'Madurai',
            'delivery_pincode' => '625020',
            'items_total' => 200, 'grand_total' => 225, 'merchant_payout' => 176,
            'placed_at' => $pickedUp->copy()->subMinutes(20),
            'picked_up_at' => $pickedUp,
            'delivered_at' => $pickedUp->copy()->addMinutes($minutes),
        ]);

        // forceFill: the measured legs are written by dispatch, never mass
        // assigned, so create() drops them silently.
        if ($metres !== null) {
            $order->forceFill(['last_mile_metres' => $metres])->save();
        }

        return $order;
    }

    /**
     * Run the report and hand back everything it printed.
     *
     * Chaining several expectsOutputToContain() calls does not reliably check
     * them all, so the output is captured once and asserted against directly.
     *
     * @param  array<string, mixed>  $options
     */
    private function report(array $options = ['--days' => 1]): string
    {
        Artisan::call('nexmile:delivery-times', $options);

        return Artisan::output();
    }

    public function test_it_says_so_when_there_is_nothing_to_measure(): void
    {
        // A promise with no orders behind it is arithmetic, and the report
        // should say that rather than printing a confident zero.
        $this->assertStringContainsString('No delivered orders', $this->report());
    }

    public function test_it_reports_the_share_that_met_the_promise(): void
    {
        $shop = $this->merchant();

        // Nine inside fifteen minutes, one well outside.
        foreach ([6, 7, 8, 8, 9, 9, 10, 11, 12] as $minutes) {
            $this->delivery($shop, $minutes);
        }

        $this->delivery($shop, 31);

        $output = $this->report();

        $this->assertStringContainsString('Orders measured:   10', $output);
        // The median is what marketing wants to quote.
        $this->assertStringContainsString('9:00', $output);
        $this->assertStringContainsString('90.0%', $output);
    }

    public function test_the_ninetieth_percentile_exposes_what_the_median_hides(): void
    {
        $shop = $this->merchant();

        /*
         * A median of eight minutes reads like a promise comfortably kept.
         * One order in ten taking twenty-eight is what the town actually talks
         * about, and only the p90 shows it.
         */
        foreach (range(1, 9) as $ignored) {
            $this->delivery($shop, 8);
        }

        $this->delivery($shop, 28);

        $output = $this->report();

        $this->assertStringContainsString('8:00', $output);
        $this->assertStringContainsString('28:00', $output);
    }

    public function test_a_breach_is_named_so_it_can_be_looked_at(): void
    {
        $shop = $this->merchant();

        $this->delivery($shop, 7, 400);
        $slow = $this->delivery($shop, 24, 1600);

        // A promise is kept or broken one order at a time, and the reason
        // lives in the breach rather than in the average.
        $output = $this->report();

        $this->assertStringContainsString($slow->order_number, $output);
        $this->assertStringContainsString('1600 m', $output);
    }

    public function test_distance_bands_separate_a_short_hop_from_a_long_way_round(): void
    {
        $shop = $this->merchant();

        $this->delivery($shop, 5, 300);
        $this->delivery($shop, 6, 450);
        $this->delivery($shop, 18, 1700);

        /*
         * A kilometre around a level crossing is a different job from a
         * kilometre down one straight road. Without the split, the second gets
         * averaged into looking fine and the promise keeps breaking in the
         * same few streets.
         */
        $output = $this->report();

        $this->assertStringContainsString('0–500 m', $output);
        $this->assertStringContainsString('1500 m+', $output);
    }

    public function test_the_promise_can_be_measured_against_a_different_target(): void
    {
        $shop = $this->merchant();

        $this->delivery($shop, 18);
        $this->delivery($shop, 22);

        // Both breach fifteen; both keep twenty-five. What the promise is
        // worth is a decision, and the report should answer either question.
        $this->assertStringContainsString(
            'Within 25 min:    100.0%',
            $this->report(['--days' => 1, '--target' => 25]),
        );
    }

    public function test_an_order_still_in_a_bag_is_not_counted(): void
    {
        $shop = $this->merchant();
        $this->delivery($shop, 9);

        // Picked up, never delivered. Counting it as zero would flatter the
        // median with the one order that went worst.
        $this->delivery($shop, 9)->forceFill([
            'status' => OrderStatus::PickedUp,
            'delivered_at' => null,
        ])->save();

        $this->assertStringContainsString('Orders measured:   1', $this->report());
    }
}
