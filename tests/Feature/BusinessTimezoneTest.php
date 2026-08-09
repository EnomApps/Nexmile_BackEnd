<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Services\Admin\DashboardService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * The platform runs on the local clock.
 *
 * Almost everything it decides is a local-clock question — whether a kitchen
 * is open, which day an order counts toward, when a deal window closes. Under
 * UTC a merchant open 09:00–22:00 was told they were closed until half past
 * two in the afternoon, because 09:00 IST is 03:30 UTC.
 */
class BusinessTimezoneTest extends CheckoutTest
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_application_runs_on_indian_time(): void
    {
        $this->assertSame('Asia/Kolkata', config('app.timezone'));

        // PHP's own clock follows, so a date() call agrees with Carbon.
        $this->assertSame('Asia/Kolkata', date_default_timezone_get());
    }

    public function test_a_shop_open_nine_to_ten_is_open_at_midday(): void
    {
        // Under UTC this instant was 06:30 and read as closed — the exact hour
        // a lunchtime kitchen most needs to be visible.
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00'));

        $shop = $this->restaurant();
        $shop->operatingHours()->create([
            'day_of_week' => now()->dayOfWeek,
            'opens_at' => '09:00',
            'closes_at' => '22:00',
            'is_closed' => false,
        ]);

        $this->assertTrue($shop->fresh()->isWithinOperatingHours());
        $this->assertTrue($shop->fresh()->isOpenNow());
    }

    public function test_a_customer_sees_that_shop_as_open(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00'));

        $shop = $this->restaurant();
        $this->dish($shop);
        $shop->operatingHours()->create([
            'day_of_week' => now()->dayOfWeek,
            'opens_at' => '09:00', 'closes_at' => '22:00', 'is_closed' => false,
        ]);

        Sanctum::actingAs($this->customer());

        $this->getJson("/api/v1/restaurants/{$shop->id}")
            ->assertOk()
            ->assertJsonPath('data.is_open', true)
            ->assertJsonPath('data.within_operating_hours', true);
    }

    public function test_the_day_of_week_is_the_local_day_late_in_the_evening(): void
    {
        /*
         * 23:00 Wednesday in India is 17:30 Wednesday UTC, so this particular
         * hour agrees. The one that does not is the small hours: 01:00
         * Thursday IST is 19:30 Wednesday UTC, and a shop closed on Wednesday
         * would wrongly be shut on Thursday morning.
         */
        Carbon::setTestNow(Carbon::parse('2026-08-13 01:00:00')); // Thursday

        $shop = $this->restaurant();

        // Open Thursday, closed Wednesday.
        $shop->operatingHours()->create([
            'day_of_week' => 4, 'opens_at' => '00:00', 'closes_at' => '23:59', 'is_closed' => false,
        ]);
        $shop->operatingHours()->create([
            'day_of_week' => 3, 'opens_at' => '09:00', 'closes_at' => '22:00', 'is_closed' => true,
        ]);

        $this->assertTrue($shop->fresh()->isWithinOperatingHours());
    }

    public function test_a_days_takings_are_counted_on_the_local_day(): void
    {
        /*
         * 03:00 IST is 21:30 the previous day in UTC. An order taken at three
         * in the morning belongs to the night that is still going on, and
         * under UTC it landed in yesterday's totals.
         */
        Carbon::setTestNow(Carbon::parse('2026-08-12 03:00:00'));

        $shop = $this->restaurant();
        $shop->orders()->create([
            'order_number' => 'NXTZ0001',
            'user_id' => $this->customer()->id,
            'status' => OrderStatus::Delivered,
            'items_total' => 400, 'grand_total' => 420,
            'commission_amount' => 60, 'merchant_payout' => 340,
            'placed_at' => now(), 'delivered_at' => now(),
        ]);

        $stats = app(DashboardService::class)->forDay();

        $this->assertSame('2026-08-12', $stats['day']);
        $this->assertSame(1, $stats['orders']['delivered']);
        $this->assertMoney(420.0, $stats['money']['gross']);
    }
}
