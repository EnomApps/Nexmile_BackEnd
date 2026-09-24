<?php

namespace Tests\Feature;

use App\Enums\FulfilmentType;
use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\Rider;
use App\Models\RiderPayout;
use App\Models\User;
use App\Services\Riders\PayoutRunService;
use App\Services\Riders\TdsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What a rider is actually paid, and what is withheld on the way.
 *
 * TDS is money taken from somebody's wages, so the arithmetic here is not a
 * display detail. Deducting below the threshold takes money from riders who
 * never owed it; deducting at the wrong rate leaves the platform owing the
 * difference; reading the financial year as January under-deducts for nine
 * months and surfaces as a demand notice much later.
 */
class RiderPayoutRunTest extends TestCase
{
    use RefreshDatabase;

    /** A Wednesday, mid-week, so week boundaries are actually exercised. */
    private const MIDWEEK = '2026-09-09 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::MIDWEEK));
        config(['payouts.minimum_transfer' => 100]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function rider(?string $pan = 'ABCDE1234F'): Rider
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'name' => 'Rider '.$n,
            'phone' => '9611'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'email' => "payout{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Rider,
            'status' => UserStatus::Active,
        ]);

        return $user->rider()->create([
            'full_name' => 'Selvam K',
            'vehicle_type' => 'motorcycle',
            'pan' => $pan,
            'kyc_status' => KycStatus::Verified,
            'driving_licence_expiry' => now()->addYear(),
            'insurance_expiry' => now()->addYear(),
        ]);
    }

    private function merchant(): Merchant
    {
        static $n = 0;
        $n++;

        $owner = User::create([
            'name' => 'Owner '.$n,
            'phone' => '9622'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'email' => "payshop{$n}@example.in",
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
            'is_accepting_orders' => true,
        ]);
    }

    /** A delivered order paying the rider this much, on this day. */
    private function delivery(Rider $rider, float $pay, ?Carbon $on = null): void
    {
        static $n = 0;
        $n++;

        $on ??= Carbon::parse(self::MIDWEEK);

        $customer = User::create([
            'name' => 'Customer '.$n,
            'phone' => '9633'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'email' => "paycust{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Customer,
            'status' => UserStatus::Active,
        ]);

        $order = $this->merchant()->orders()->create([
            'order_number' => 'NXP'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'user_id' => $customer->id,
            'rider_id' => $rider->id,
            'status' => OrderStatus::Delivered,
            'fulfilment_type' => FulfilmentType::Delivery,
            'delivery_contact_name' => 'Meena',
            'delivery_line1' => '4 Gandhi Nagar',
            'delivery_city' => 'Madurai',
            'delivery_pincode' => '625020',
            'items_total' => 200, 'grand_total' => 225, 'merchant_payout' => 176,
            'placed_at' => $on,
        ]);

        // Both written by dispatch, never mass assigned.
        $order->forceFill(['rider_payout' => $pay, 'delivered_at' => $on])->save();
    }

    private function payRun(?Carbon $day = null)
    {
        return app(PayoutRunService::class)->run($day ?? Carbon::parse(self::MIDWEEK));
    }

    // ------------------------------------------------------------ the basics

    public function test_a_weeks_deliveries_become_one_payout(): void
    {
        $rider = $this->rider();

        foreach ([25.00, 35.00, 40.50] as $pay) {
            $this->delivery($rider, $pay);
        }

        $payout = $this->payRun()->sole();

        $this->assertEqualsWithDelta(100.50, (float) $payout->gross, 0.01);
        $this->assertEqualsWithDelta(100.50, (float) $payout->net, 0.01);
        $this->assertSame(RiderPayout::PENDING, $payout->status);
    }

    public function test_the_week_runs_monday_to_sunday(): void
    {
        $rider = $this->rider();

        // Inside the week containing Wednesday 9 September 2026.
        $this->delivery($rider, 50, Carbon::parse('2026-09-07 09:00:00'));
        $this->delivery($rider, 50, Carbon::parse('2026-09-13 23:30:00'));
        // Outside it, either side.
        $this->delivery($rider, 999, Carbon::parse('2026-09-06 23:00:00'));
        $this->delivery($rider, 999, Carbon::parse('2026-09-14 00:30:00'));

        $payout = $this->payRun()->sole();

        $this->assertEqualsWithDelta(100.00, (float) $payout->gross, 0.01);
        $this->assertSame('2026-09-07', $payout->period_start->toDateString());
        $this->assertSame('2026-09-13', $payout->period_end->toDateString());
    }

    public function test_running_twice_does_not_pay_twice(): void
    {
        $rider = $this->rider();
        $this->delivery($rider, 300);

        $this->payRun();
        $this->payRun();

        // A cron that fires again, or somebody checking it worked. Paying a
        // week twice is the one mistake that cannot be undone by editing rows.
        $this->assertSame(1, RiderPayout::count());
        $this->assertEqualsWithDelta(300.00, (float) RiderPayout::sole()->gross, 0.01);
    }

    public function test_a_week_already_paid_is_never_touched_again(): void
    {
        $rider = $this->rider();
        $this->delivery($rider, 300);

        $payout = $this->payRun()->sole();
        $payout->forceFill(['status' => RiderPayout::PAID, 'paid_at' => now()])->save();

        // More work lands, backdated into a week whose money already left.
        $this->delivery($rider, 500);
        $this->payRun();

        $this->assertEqualsWithDelta(300.00, (float) $payout->fresh()->gross, 0.01);
        $this->assertSame(RiderPayout::PAID, $payout->fresh()->status);
    }

    public function test_a_rider_who_did_nothing_gets_no_row(): void
    {
        $this->rider();

        // An empty statement is a question a rider has to ask about.
        $this->assertCount(0, $this->payRun());
    }

    // ------------------------------------------------------- small amounts

    public function test_too_little_to_transfer_is_carried_not_sent(): void
    {
        $rider = $this->rider();
        $this->delivery($rider, 40);

        $payout = $this->payRun()->sole();

        // A ₹40 transfer costs more in fees and reconciliation than it moves.
        $this->assertSame(RiderPayout::CARRIED, $payout->status);
    }

    public function test_what_was_carried_is_added_to_the_next_week(): void
    {
        $rider = $this->rider();

        $this->delivery($rider, 40, Carbon::parse('2026-09-09 12:00:00'));
        $this->payRun(Carbon::parse('2026-09-09'));

        $this->delivery($rider, 200, Carbon::parse('2026-09-16 12:00:00'));
        $next = $this->payRun(Carbon::parse('2026-09-16'))->sole();

        // A rider who earned ₹40 three weeks running is owed ₹120, not three
        // rows nobody ever pays.
        $this->assertEqualsWithDelta(240.00, (float) $next->gross, 0.01);
        $this->assertSame(RiderPayout::PENDING, $next->status);
    }

    public function test_the_same_carried_amount_cannot_land_in_two_weeks(): void
    {
        $rider = $this->rider();

        $this->delivery($rider, 40, Carbon::parse('2026-09-09 12:00:00'));
        $this->payRun(Carbon::parse('2026-09-09'));

        $this->delivery($rider, 200, Carbon::parse('2026-09-16 12:00:00'));
        $this->payRun(Carbon::parse('2026-09-16'));
        $this->payRun(Carbon::parse('2026-09-16'));

        $week = RiderPayout::whereDate('period_start', '2026-09-14')->sole();

        /*
         * The carry is decided once and kept. Recomputing it on a second run
         * finds the earlier rows already consumed and rebuilds the total
         * without them — the rider quietly losing exactly the amount that was
         * too small to send, which is also too small for anyone to notice.
         */
        $this->assertEqualsWithDelta(240.00, (float) $week->gross, 0.01);
        $this->assertEqualsWithDelta(40.00, (float) $week->carried_in, 0.01);
    }

    // --------------------------------------------------------------- the TDS

    public function test_nothing_is_withheld_while_tds_is_switched_off(): void
    {
        config(['payouts.tds.enabled' => false]);

        $rider = $this->rider();
        $this->delivery($rider, 5000);

        $payout = $this->payRun()->sole();

        $this->assertEqualsWithDelta(0.0, (float) $payout->tds, 0.01);
        $this->assertEqualsWithDelta(5000.00, (float) $payout->net, 0.01);
    }

    public function test_nothing_is_withheld_below_the_annual_threshold(): void
    {
        config(['payouts.tds.enabled' => true, 'payouts.tds.annual_threshold' => 100000]);

        $rider = $this->rider();
        $this->delivery($rider, 6000);

        /*
         * The point most easily got wrong. Deducting from rupee one takes
         * money from riders who never owe it, and leaves the platform
         * explaining why to people who cannot afford the explanation.
         */
        $this->assertEqualsWithDelta(0.0, (float) $this->payRun()->sole()->tds, 0.01);
    }

    public function test_the_week_that_crosses_the_threshold_is_the_week_it_starts(): void
    {
        config(['payouts.tds.enabled' => true, 'payouts.tds.annual_threshold' => 1000]);

        $rider = $this->rider();
        $this->delivery($rider, 1500);

        $payout = $this->payRun()->sole();

        // 1% of 1500.
        $this->assertEqualsWithDelta(15.00, (float) $payout->tds, 0.01);
        $this->assertEqualsWithDelta(1485.00, (float) $payout->net, 0.01);
        $this->assertEqualsWithDelta(1.0, (float) $payout->tds_rate, 0.01);
    }

    public function test_no_pan_means_the_penalty_rate(): void
    {
        config(['payouts.tds.enabled' => true, 'payouts.tds.annual_threshold' => 1000]);

        $rider = $this->rider(pan: null);
        $this->delivery($rider, 2000);

        /*
         * 20% under section 206AA, and not a choice — deducting 1% from
         * somebody with no PAN leaves the platform owing the difference.
         */
        $this->assertEqualsWithDelta(400.00, (float) $this->payRun()->sole()->tds, 0.01);
    }

    public function test_a_malformed_pan_is_treated_as_no_pan(): void
    {
        config(['payouts.tds.enabled' => true, 'payouts.tds.annual_threshold' => 1000]);

        // Ten characters in the wrong shape is not a PAN, and accepting it
        // would under-deduct against a number the department will reject.
        $rider = $this->rider(pan: '1234567890');
        $this->delivery($rider, 2000);

        $this->assertEqualsWithDelta(400.00, (float) $this->payRun()->sole()->tds, 0.01);
    }

    public function test_the_rate_that_applied_is_kept_on_the_payout(): void
    {
        config(['payouts.tds.enabled' => true, 'payouts.tds.annual_threshold' => 1000]);

        $rider = $this->rider();
        $this->delivery($rider, 2000);
        $payout = $this->payRun()->sole();

        // Next year the rate changes. What was already withheld does not.
        config(['payouts.tds.rate' => 5.0]);

        $this->assertEqualsWithDelta(1.0, (float) $payout->fresh()->tds_rate, 0.01);
        $this->assertEqualsWithDelta(20.00, (float) $payout->fresh()->tds, 0.01);
    }

    public function test_withholding_never_rounds_against_the_rider(): void
    {
        config(['payouts.tds.enabled' => true, 'payouts.tds.annual_threshold' => 1]);

        $rider = $this->rider();
        $this->delivery($rider, 333.33);

        // 1% of 333.33 is 3.3333. Rounded up, the platform takes a paisa it
        // was not asked to take, every week, from everybody.
        $this->assertEqualsWithDelta(3.33, (float) $this->payRun()->sole()->tds, 0.001);
    }

    // ----------------------------------------------------- the financial year

    public function test_the_financial_year_starts_in_april(): void
    {
        $tds = app(TdsCalculator::class);

        /*
         * India's year runs April to March. Reading it as January resets every
         * rider's running total nine months early, under-deducts for the rest
         * of the year, and surfaces as a demand notice long after anybody
         * remembers writing this.
         */
        $this->assertSame(
            '2026-04-01',
            $tds->financialYearStart(Carbon::parse('2026-09-09'))->toDateString(),
        );

        // February falls in the year that began the previous April.
        $this->assertSame(
            '2025-04-01',
            $tds->financialYearStart(Carbon::parse('2026-02-11'))->toDateString(),
        );
    }

    public function test_earlier_weeks_count_towards_the_threshold(): void
    {
        config(['payouts.tds.enabled' => true, 'payouts.tds.annual_threshold' => 1000]);

        $rider = $this->rider();

        $this->delivery($rider, 600, Carbon::parse('2026-09-09 12:00:00'));
        $first = $this->payRun(Carbon::parse('2026-09-09'))->sole();

        $this->delivery($rider, 600, Carbon::parse('2026-09-16 12:00:00'));
        $second = $this->payRun(Carbon::parse('2026-09-16'))->sole();

        // 600 alone is under; 600 + 600 is over, so the second week is where
        // deduction begins.
        $this->assertEqualsWithDelta(0.0, (float) $first->fresh()->tds, 0.01);
        $this->assertEqualsWithDelta(6.00, (float) $second->tds, 0.01);
        $this->assertEqualsWithDelta(600.00, (float) $second->tds_year_to_date, 0.01);
    }

    // ---------------------------------------------------------- the rider app

    public function test_a_rider_sees_the_week_and_what_was_taken(): void
    {
        config(['payouts.tds.enabled' => true, 'payouts.tds.annual_threshold' => 1000]);

        $rider = $this->rider();
        $this->delivery($rider, 6626);
        $this->payRun();

        Sanctum::actingAs($rider->user);

        $row = $this->getJson('/api/v1/rider/payouts')->assertOk()->json('data.0');

        $this->assertEqualsWithDelta(6626.00, $row['gross'], 0.01);
        $this->assertEqualsWithDelta(66.26, $row['deductions_total'], 0.01);
        $this->assertEqualsWithDelta(6559.74, $row['net'], 0.01);

        // The line has to say where the money went, not just that it left.
        $this->assertStringContainsString('TDS', $row['deductions'][0]['label']);
        $this->assertStringContainsString('claim this back', $row['deductions'][0]['note']);
    }

    public function test_a_week_with_nothing_withheld_shows_no_deductions(): void
    {
        config(['payouts.tds.enabled' => false]);

        $rider = $this->rider();
        $this->delivery($rider, 500);
        $this->payRun();

        Sanctum::actingAs($rider->user);

        // A "Deductions" heading with nothing under it makes a rider wonder
        // what was taken.
        $this->assertSame([], $this->getJson('/api/v1/rider/payouts')->assertOk()->json('data.0.deductions'));
    }

    public function test_a_carried_week_is_not_shown_as_a_payout(): void
    {
        $rider = $this->rider();
        $this->delivery($rider, 40);
        $this->payRun();

        Sanctum::actingAs($rider->user);

        // An accounting step, not something that happened to the rider. The
        // money appears in the week it is actually paid.
        $this->getJson('/api/v1/rider/payouts')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_rider_cannot_read_another_riders_payout(): void
    {
        $rider = $this->rider();
        $this->delivery($rider, 500);
        $payout = $this->payRun()->sole();

        Sanctum::actingAs($this->rider()->user);

        $this->getJson("/api/v1/rider/payouts/{$payout->id}")->assertNotFound();
    }
}
