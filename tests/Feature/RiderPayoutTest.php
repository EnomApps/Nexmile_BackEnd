<?php

namespace Tests\Feature;

use App\Enums\FulfilmentType;
use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\RiderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Rider;
use App\Models\User;
use App\Services\Riders\RiderPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What a rider earns for one delivery.
 *
 * The numbers here are the ones a rider will check against their own arithmetic
 * at the end of a shift, so each test states the sum it expects rather than
 * asserting on a magic total.
 */
class RiderPayoutTest extends TestCase
{
    use RefreshDatabase;

    /** Madurai. Distances below are measured from here. */
    private const LAT = 9.9195;

    private const LNG = 78.1193;

    protected function setUp(): void
    {
        parent::setUp();

        // Outside the peak windows, so incentives do not muddy the base sums.
        Carbon::setTestNow(Carbon::parse('2026-08-29 16:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function user(UserRole $role, string $prefix): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => ucfirst($prefix).' '.$n,
            'phone' => '94000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "pay{$prefix}{$n}@example.in",
            'password' => 'secret',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    private function restaurant(): Merchant
    {
        return Merchant::create([
            'user_id' => $this->user(UserRole::Merchant, 'shop')->id,
            'business_name' => 'Ponnusamy Hotel',
            'owner_name' => 'Owner',
            'address_line1' => '9 Anna Salai',
            'city' => 'Madurai',
            'pincode' => '625001',
            'latitude' => self::LAT,
            'longitude' => self::LNG,
            'kyc_status' => KycStatus::Verified,
            'is_accepting_orders' => true,
        ]);
    }

    private function rider(): Rider
    {
        return $this->user(UserRole::Rider, 'rider')->rider()->create([
            'full_name' => 'Selvam K',
            'vehicle_type' => 'motorcycle',
            'kyc_status' => KycStatus::Verified,
            'driving_licence_expiry' => now()->addYear(),
            'insurance_expiry' => now()->addYear(),
            'duty_status' => RiderStatus::Available,
        ]);
    }

    private function order(Merchant $merchant, Rider $rider, array $attributes = []): Order
    {
        static $n = 0;
        $n++;

        return $merchant->orders()->create([
            'order_number' => 'NXE'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'user_id' => $this->user(UserRole::Customer, 'cust')->id,
            'rider_id' => $rider->id,
            'status' => OrderStatus::Delivered,
            'fulfilment_type' => FulfilmentType::Delivery,
            'delivery_contact_name' => 'Meena',
            'delivery_line1' => '4 Gandhi Nagar',
            'delivery_city' => 'Madurai',
            'delivery_pincode' => '625020',
            'items_total' => 300,
            'delivery_fee' => 25,
            'commission_amount' => 45,
            'grand_total' => 340,
            'merchant_payout' => 255,
            'placed_at' => now(),
            'delivered_at' => now(),
            ...$attributes,
        ])->fresh();
    }

    public function test_a_short_hop_pays_the_minimum(): void
    {
        /*
         * First mile ₹8 + last mile ₹15 = ₹23, topped up to the ₹25 floor.
         * A trip next door still costs a rider a trip, and a number they can
         * rely on recruits better than an average they cannot predict.
         */
        $order = $this->order($this->restaurant(), $this->rider(), [
            'first_mile_metres' => 300,
            'last_mile_metres' => 600,
        ]);

        $breakdown = app(RiderPayoutService::class)->calculate($order);

        $this->assertEqualsWithDelta(8.00, $breakdown['first_mile']['amount'], 0.01);
        $this->assertEqualsWithDelta(15.00, $breakdown['last_mile']['amount'], 0.01);
        $this->assertEqualsWithDelta(2.00, $breakdown['minimum_top_up'], 0.01);
        $this->assertEqualsWithDelta(25.00, $breakdown['total'], 0.01);
    }

    public function test_distance_beyond_the_base_is_paid_per_kilometre(): void
    {
        // First mile 2 km: ₹8 + 1 km beyond × ₹6 = ₹14.
        // Last mile 3 km: ₹15 + 1.5 km beyond × ₹8 = ₹27.
        $order = $this->order($this->restaurant(), $this->rider(), [
            'first_mile_metres' => 2000,
            'last_mile_metres' => 3000,
        ]);

        $breakdown = app(RiderPayoutService::class)->calculate($order);

        $this->assertEqualsWithDelta(14.00, $breakdown['first_mile']['amount'], 0.01);
        $this->assertEqualsWithDelta(27.00, $breakdown['last_mile']['amount'], 0.01);
        $this->assertEqualsWithDelta(41.00, $breakdown['total'], 0.01);
        // Well past the floor, so no top-up.
        $this->assertEqualsWithDelta(0.00, $breakdown['minimum_top_up'], 0.01);
    }

    public function test_waiting_is_free_for_the_first_five_minutes(): void
    {
        $order = $this->order($this->restaurant(), $this->rider(), [
            'first_mile_metres' => 300,
            'last_mile_metres' => 600,
            'arrived_at' => now()->subMinutes(12),
            'picked_up_at' => now()->subMinutes(5),
        ]);

        $breakdown = app(RiderPayoutService::class)->calculate($order);

        // Seven minutes waited, five free, two paid at ₹1.
        $this->assertSame(7, $breakdown['waiting']['minutes']);
        $this->assertSame(2, $breakdown['waiting']['paid_minutes']);
        $this->assertEqualsWithDelta(2.00, $breakdown['waiting']['amount'], 0.01);
        // 8 + 2 + 15 = 25, which happens to equal the floor exactly.
        $this->assertEqualsWithDelta(25.00, $breakdown['total'], 0.01);
    }

    public function test_waiting_is_capped(): void
    {
        /*
         * A rider cannot be left earning indefinitely because a kitchen forgot
         * to hand over, and nor can the platform be billed for it. Past the
         * cap it is a support problem, not a payout one.
         */
        $order = $this->order($this->restaurant(), $this->rider(), [
            'first_mile_metres' => 300,
            'last_mile_metres' => 600,
            'arrived_at' => now()->subMinutes(200),
            'picked_up_at' => now(),
        ]);

        $breakdown = app(RiderPayoutService::class)->calculate($order);

        $this->assertSame((int) config('rider_pay.waiting.max_minutes'), $breakdown['waiting']['minutes']);
    }

    public function test_waiting_is_not_paid_when_arrival_was_never_recorded(): void
    {
        /*
         * The gap between accepting and collecting is travel plus waiting.
         * Paying for it would pay a rider for their own journey twice — once
         * in the first mile and again by the minute.
         */
        $order = $this->order($this->restaurant(), $this->rider(), [
            'first_mile_metres' => 300,
            'last_mile_metres' => 600,
            'arrived_at' => null,
            'picked_up_at' => now()->subMinutes(40),
        ]);

        $breakdown = app(RiderPayoutService::class)->calculate($order);

        $this->assertEqualsWithDelta(0.00, $breakdown['waiting']['amount'], 0.01);
        $this->assertNull($breakdown['waiting']['minutes']);
    }

    public function test_an_unknown_distance_pays_the_base_rather_than_nothing(): void
    {
        // The rider made the journey either way. A missing GPS fix is our
        // problem, not theirs.
        $order = $this->order($this->restaurant(), $this->rider(), [
            'first_mile_metres' => null,
            'last_mile_metres' => null,
            'distance_metres' => null,
            'accepted_latitude' => null,
        ]);

        $breakdown = app(RiderPayoutService::class)->calculate($order);

        $this->assertEqualsWithDelta(8.00, $breakdown['first_mile']['amount'], 0.01);
        $this->assertEqualsWithDelta(15.00, $breakdown['last_mile']['amount'], 0.01);
        $this->assertEqualsWithDelta(25.00, $breakdown['total'], 0.01);
    }

    public function test_peak_hours_add_an_incentive(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 20:00:00'));

        $order = $this->order($this->restaurant(), $this->rider(), [
            'first_mile_metres' => 300,
            'last_mile_metres' => 600,
        ]);

        $breakdown = app(RiderPayoutService::class)->calculate($order);

        $this->assertArrayHasKey('peak', $breakdown['incentives']);
        // 25 base + 10 peak, and the order earns 45 + 25 = 70, so the 60% cap
        // (42) does not bite.
        $this->assertEqualsWithDelta(35.00, $breakdown['total'], 0.01);
    }

    public function test_incentives_cannot_cost_more_than_the_order_makes(): void
    {
        /*
         * A flat peak-plus-weather bonus on a small order can cost more than
         * the order earns, and it lands exactly on the wet Friday evening when
         * the most orders are running.
         */
        Carbon::setTestNow(Carbon::parse('2026-08-29 20:00:00'));
        config(['rider_pay.incentives.bad_weather_active' => true]);

        // A free-delivery order earns commission alone, and a small one at
        // that: 60% of ₹10 is ₹6, against ₹15 of incentives.
        $order = $this->order($this->restaurant(), $this->rider(), [
            'first_mile_metres' => 300,
            'last_mile_metres' => 600,
            'commission_amount' => 10,
            'delivery_fee' => 0,
        ]);

        $breakdown = app(RiderPayoutService::class)->calculate($order);

        $this->assertEqualsWithDelta(6.00, $breakdown['incentive_total'], 0.01);
        $this->assertEqualsWithDelta(15.00, $breakdown['incentives']['capped_from'], 0.01);
        $this->assertEqualsWithDelta(31.00, $breakdown['total'], 0.01);
    }

    public function test_settling_stores_the_payout_and_never_pays_twice(): void
    {
        // Delivering is one-way, but a retried job or a support tool must not
        // pay a second time.
        $order = $this->order($this->restaurant(), $this->rider(), [
            'first_mile_metres' => 300,
            'last_mile_metres' => 600,
        ]);

        $service = app(RiderPayoutService::class);

        $service->settle($order);
        $first = $order->fresh()->rider_payout;

        config(['rider_pay.minimum_payout' => 999]);
        $service->settle($order->fresh());

        $this->assertEqualsWithDelta((float) $first, (float) $order->fresh()->rider_payout, 0.01);
        $this->assertNotNull($order->fresh()->rider_payout_breakdown);
    }

    public function test_a_rider_can_see_what_they_earned(): void
    {
        $rider = $this->rider();
        $merchant = $this->restaurant();

        foreach (range(1, 3) as $ignored) {
            $order = $this->order($merchant, $rider, [
                'first_mile_metres' => 300,
                'last_mile_metres' => 600,
            ]);
            app(RiderPayoutService::class)->settle($order);
        }

        Sanctum::actingAs($rider->user);

        $response = $this->getJson('/api/v1/rider/earnings')->assertOk();

        $response->assertJsonPath('meta.today.deliveries', 3)
            ->assertJsonCount(3, 'data');

        // Money is a JSON number and loses its zero fraction: 75.00 arrives as
        // 75 and decodes as an int, so compare the value not the type.
        $this->assertEqualsWithDelta(75, (float) $response->json('meta.today.earned'), 0.01);

        // The parts, not just the total — it is what makes the rate card
        // believable when a rider checks it against their own arithmetic.
        $this->assertNotNull($response->json('data.0.breakdown.first_mile'));
    }

    public function test_earnings_only_count_delivered_orders(): void
    {
        // Food in a bag is work done but not money owed. Counting it makes
        // every total a promise that has to be taken back on a cancellation.
        $rider = $this->rider();
        $merchant = $this->restaurant();

        $delivered = $this->order($merchant, $rider, ['first_mile_metres' => 300, 'last_mile_metres' => 600]);
        app(RiderPayoutService::class)->settle($delivered);

        $inFlight = $this->order($merchant, $rider, [
            'status' => OrderStatus::PickedUp,
            'delivered_at' => null,
        ]);
        $inFlight->forceFill(['rider_payout' => 25])->save();

        Sanctum::actingAs($rider->user);

        $earnings = $this->getJson('/api/v1/rider/earnings')->assertOk();

        $earnings->assertJsonPath('meta.today.deliveries', 1);
        $this->assertEqualsWithDelta(25, (float) $earnings->json('meta.today.earned'), 0.01);
    }

    public function test_another_riders_earnings_are_not_visible(): void
    {
        $theirs = $this->rider();
        $order = $this->order($this->restaurant(), $theirs, [
            'first_mile_metres' => 300, 'last_mile_metres' => 600,
        ]);
        app(RiderPayoutService::class)->settle($order);

        Sanctum::actingAs($this->rider()->user);

        $this->getJson('/api/v1/rider/earnings')
            ->assertOk()
            ->assertJsonPath('meta.today.deliveries', 0)
            ->assertJsonCount(0, 'data');
    }
}
