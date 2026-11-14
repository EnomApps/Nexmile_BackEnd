<?php

namespace Tests\Feature;

use App\Contracts\RouteProvider;
use App\Services\LiveState\RiderLocationService;
use Laravel\Sanctum\Sanctum;

/**
 * The map a customer watches while their food is on its way.
 *
 * Everything here is read every few seconds, for the whole delivery, by every
 * waiting customer. That makes two kinds of mistake expensive in ways a normal
 * endpoint's are not: a wrong timestamp shows a rider frozen in a street they
 * left ten minutes ago, and an uncached route turns one dinner into a hundred
 * billed Directions calls.
 */
class LiveTrackingTest extends DispatchTest
{
    /** A rider roughly 700 m north of the restaurant. */
    private const RIDER_LAT = 9.9260;

    private const RIDER_LNG = 78.1193;

    /** @param array<string, mixed> $overrides */
    private function positionIs(array $overrides = []): void
    {
        $this->mock(RiderLocationService::class, function ($mock) use ($overrides) {
            $mock->shouldReceive('state')->andReturn([
                'latitude' => (string) self::RIDER_LAT,
                'longitude' => (string) self::RIDER_LNG,
                'heading' => '135',
                'recorded_at' => now()->subSeconds(5)->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
                ...$overrides,
            ])->byDefault();

            $mock->shouldReceive('updateLocation')->andReturnTrue()->byDefault();
            $mock->shouldReceive('setDutyStatus')->andReturnNull()->byDefault();
            $mock->shouldReceive('goOffline')->andReturnNull()->byDefault();
            $mock->shouldReceive('isPresent')->andReturnTrue()->byDefault();
            $mock->shouldReceive('ridersNear')->andReturn([])->byDefault();
        });
    }

    /** An order a rider has accepted but not yet collected. */
    private function assignedOrder(): array
    {
        $order = $this->readyOrder();
        $customer = $order->customer;
        $rider = $this->rider();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")->assertOk();

        return [$order, $customer, $rider];
    }

    private function pickUp($order, $rider): void
    {
        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/pickup", [
            'pickup_code' => $order->fresh()->pickup_code,
        ])->assertOk();
    }

    public function test_the_position_is_flat_and_carries_a_heading(): void
    {
        /*
         * Flat rather than nested under `location`, and with the bearing the
         * phone reported — without it the marker slides sideways down a
         * one-way street instead of pointing where the bike is going.
         */
        $this->positionIs();
        [$order, $customer] = $this->assignedOrder();

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->assertJsonPath('data.rider.latitude', self::RIDER_LAT)
            ->assertJsonPath('data.rider.longitude', self::RIDER_LNG)
            ->assertJsonPath('data.rider.heading', 135);
    }

    public function test_the_timestamp_is_when_the_phone_took_the_fix(): void
    {
        /*
         * The app shows "location updating" from this. Answering with the
         * server clock would mean a rider whose phone died ten minutes ago
         * still looked live, which is the one thing the field is for.
         */
        $fix = now()->subMinutes(3)->startOfSecond();

        $this->positionIs([
            'recorded_at' => $fix->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);

        [$order, $customer] = $this->assignedOrder();

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->assertJsonPath('data.rider.updated_at', $fix->toIso8601String());
    }

    public function test_an_older_app_without_a_fix_time_still_works(): void
    {
        // recorded_at is optional on the ping, so a build that predates it
        // must not blank the timestamp entirely.
        $received = now()->subSeconds(20)->startOfSecond();

        $this->positionIs(['recorded_at' => '', 'updated_at' => $received->toIso8601String()]);
        [$order, $customer] = $this->assignedOrder();

        Sanctum::actingAs($customer);

        $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->assertJsonPath('data.rider.updated_at', $received->toIso8601String());
    }

    public function test_an_unknown_heading_is_null_not_the_word_null(): void
    {
        /*
         * Redis hashes hold strings. A heading stored as a literal 'null'
         * reads back as four characters, casts to 0.0, and points every
         * marker due north.
         */
        $this->positionIs(['heading' => '']);
        [$order, $customer] = $this->assignedOrder();

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->assertJsonPath('data.rider.heading', null);
    }

    public function test_the_ping_accepts_a_heading_and_a_fix_time(): void
    {
        $rider = $this->rider();

        Sanctum::actingAs($rider->user);
        $this->postJson('/api/v1/rider/location', [
            'latitude' => 9.93,
            'longitude' => 78.12,
            'accuracy_metres' => 8,
            'heading' => 135,
            'speed' => 6.2,
            'recorded_at' => now()->subSeconds(4)->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.accepted', true);
    }

    public function test_a_ping_that_lost_a_race_is_reported_as_not_accepted(): void
    {
        /*
         * Out-of-order delivery is normal on a mobile network. The app did
         * nothing wrong and has nothing to retry, so this is a 200 that says
         * so rather than an error it would log.
         */
        $this->mock(RiderLocationService::class, function ($mock) {
            $mock->shouldReceive('updateLocation')->andReturnFalse();
            $mock->shouldReceive('state')->andReturn([])->byDefault();
            $mock->shouldReceive('setDutyStatus')->andReturnNull()->byDefault();
            $mock->shouldReceive('isPresent')->andReturnTrue()->byDefault();
        });

        $rider = $this->rider();

        Sanctum::actingAs($rider->user);
        $this->postJson('/api/v1/rider/location', [
            'latitude' => 9.93,
            'longitude' => 78.12,
            'recorded_at' => now()->subMinute()->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.accepted', false);
    }

    public function test_a_nonsense_heading_is_refused(): void
    {
        $rider = $this->rider();

        Sanctum::actingAs($rider->user);
        $this->postJson('/api/v1/rider/location', [
            'latitude' => 9.93, 'longitude' => 78.12, 'heading' => 540,
        ])->assertStatus(422)->assertJsonValidationErrors('heading');
    }

    public function test_an_arrival_time_is_given_before_the_food_is_collected(): void
    {
        /*
         * Three things still have to happen: the ride to the kitchen, the
         * handover, and the delivery itself. Counting only the last is how a
         * fifteen minute promise quietly becomes eighteen.
         */
        $this->positionIs();
        [$order, $customer] = $this->assignedOrder();

        Sanctum::actingAs($customer);
        $minutes = $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->json('data.estimated_minutes');

        $this->assertGreaterThan(
            (int) config('tracking.handover_minutes'),
            $minutes,
            'the ride to the restaurant was not counted',
        );
    }

    public function test_the_arrival_time_falls_once_the_food_is_on_the_bike(): void
    {
        $this->positionIs();
        [$order, $customer, $rider] = $this->assignedOrder();

        Sanctum::actingAs($customer);
        $before = $this->getJson("/api/v1/orders/{$order->id}/track")->json('data.estimated_minutes');

        $this->pickUp($order, $rider);

        Sanctum::actingAs($customer);
        $after = $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->assertJsonPath('data.status', 'picked_up')
            ->json('data.estimated_minutes');

        $this->assertLessThan($before, $after);
        $this->assertGreaterThanOrEqual(1, $after, 'nobody is helped by "arriving in 0 min"');
    }

    public function test_no_route_is_drawn_when_routing_is_switched_off(): void
    {
        // The default. The map still shows a moving rider and an estimate —
        // a missing key degrades the map rather than blanking it.
        config(['tracking.route.enabled' => false]);

        $this->positionIs();
        [$order, $customer] = $this->assignedOrder();

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->assertJsonPath('data.route', null)
            ->assertJsonPath('data.rider.latitude', self::RIDER_LAT);
    }

    public function test_a_route_is_returned_and_its_duration_drives_the_estimate(): void
    {
        config(['tracking.route.enabled' => true]);

        $this->mock(RouteProvider::class, function ($mock) {
            $mock->shouldReceive('between')->andReturn([
                'polyline' => 'yzlcAkr}bMpG}C',
                'duration_seconds' => 480,
                'distance_metres' => 1600,
            ]);
        });

        $this->positionIs();
        [$order, $customer, $rider] = $this->assignedOrder();
        $this->pickUp($order, $rider);

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->assertJsonPath('data.route', 'yzlcAkr}bMpG}C')
            // 480 seconds, as whole minutes.
            ->assertJsonPath('data.estimated_minutes', 8);
    }

    public function test_a_route_is_computed_once_and_reused_across_polls(): void
    {
        /*
         * The reason this file exists. A customer polling every 8 seconds for
         * a 15 minute delivery is about 110 requests; at one Directions call
         * each the maps bill outruns what the delivery earns.
         */
        config(['tracking.route.enabled' => true]);

        $this->mock(RouteProvider::class, function ($mock) {
            $mock->shouldReceive('between')->once()->andReturn([
                'polyline' => 'abc',
                'duration_seconds' => 300,
                'distance_metres' => 900,
            ]);
        });

        $this->positionIs();
        [$order, $customer, $rider] = $this->assignedOrder();
        $this->pickUp($order, $rider);

        Sanctum::actingAs($customer);

        foreach (range(1, 5) as $ignored) {
            $this->getJson("/api/v1/orders/{$order->id}/track")
                ->assertOk()
                ->assertJsonPath('data.route', 'abc');
        }
    }

    public function test_the_position_disappears_once_the_order_is_delivered(): void
    {
        // A rider's movements are their own business after the handover.
        $this->positionIs();
        [$order, $customer, $rider] = $this->assignedOrder();
        $this->pickUp($order, $rider);

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->assertJsonPath('data.rider.latitude', self::RIDER_LAT);

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/deliver")->assertOk();

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            // The rider is still named and callable — a customer with a
            // problem needs that after delivery, not before.
            ->assertJsonPath('data.rider.name', 'Selvam K')
            ->assertJsonPath('data.rider.latitude', null)
            ->assertJsonPath('data.rider.heading', null)
            ->assertJsonPath('data.estimated_minutes', null);
    }

    public function test_the_apps_are_told_when_to_call_a_position_stale(): void
    {
        /*
         * Published so the backend and both apps cannot drift to different
         * ideas of stale. The alternative is the number living in three
         * codebases and being changed in one.
         */
        $this->positionIs();
        [$order, $customer] = $this->assignedOrder();

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->assertJsonPath('data.stale_after_seconds', (int) config('tracking.stale_after_seconds'));
    }

    public function test_another_customer_cannot_watch_the_delivery(): void
    {
        $this->positionIs();
        [$order] = $this->assignedOrder();

        Sanctum::actingAs($this->customer());
        $this->getJson("/api/v1/orders/{$order->id}/track")->assertNotFound();
    }

    // ------------------------------------------------- waiting, and arriving

    public function test_the_rider_stays_on_the_map_while_they_wait_at_the_counter(): void
    {
        /*
         * `reached_restaurant` sits between assigned and picked up. Gating
         * the position on a list of statuses that did not include it made the
         * rider vanish from the customer's map for exactly as long as they
         * stood at the counter — which is the part of a late delivery a
         * customer most wants to see.
         */
        $this->positionIs();
        [$order, $customer, $rider] = $this->assignedOrder();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/reached-restaurant")->assertOk();

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->assertJsonPath('data.status', 'reached_restaurant')
            ->assertJsonPath('data.rider.latitude', self::RIDER_LAT);
    }

    public function test_the_rider_stays_on_the_map_while_they_stand_at_the_door(): void
    {
        $this->positionIs();
        [$order, $customer, $rider] = $this->assignedOrder();
        $this->pickUp($order, $rider);

        Sanctum::actingAs($customer);
        $carrying = $this->getJson("/api/v1/orders/{$order->id}/track")->assertOk();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/reached-customer")->assertOk();

        Sanctum::actingAs($customer);
        $atDoor = $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->assertJsonPath('data.status', 'reached_customer')
            ->assertJsonPath('data.rider.latitude', self::RIDER_LAT);

        /*
         * And the estimate does not grow at the door. Both of these are the
         * same journey from the same position — the rider to the customer —
         * so they must be the same number. Keying the drawn leg on the
         * `picked_up` status alone instead of on the handover having happened
         * sent this branch back through "rider rides to the kitchen, plus
         * handover, plus the delivery", quietly adding minutes to an order
         * whose rider was already outside the door.
         */
        $this->assertSame(
            $carrying->json('data.estimated_minutes'),
            $atDoor->json('data.estimated_minutes'),
        );
    }

    // --------------------------------------------------------- the dispatcher

    public function test_the_customer_is_told_that_a_rider_is_being_looked_for(): void
    {
        /*
         * A different question from where the food is: an order is
         * `preparing` and `searching` at the same moment. Without this the
         * app can only show a spinner, and `no_rider_found` — food going cold
         * with nobody coming for it — looks identical to it.
         */
        $this->positionIs();
        $order = $this->readyOrder();

        Sanctum::actingAs($order->customer);
        $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->assertJsonPath('data.dispatch_status', 'searching')
            ->assertJsonPath('data.dispatch_label', 'Finding a delivery partner');
    }

    public function test_the_dispatch_state_turns_to_assigned_when_a_rider_takes_it(): void
    {
        $this->positionIs();
        [$order, $customer] = $this->assignedOrder();

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->assertJsonPath('data.dispatch_status', 'assigned');
    }

    public function test_a_phone_asking_for_tamil_is_answered_in_tamil(): void
    {
        /*
         * The API ignored Accept-Language entirely: the locale middleware ran
         * only on the web group and read the session, which a token request
         * does not have. So every app response was English however the phone
         * was set, with lang/ta sitting there unused and nothing failing
         * anywhere — the exact shape of bug a fallback hides.
         */
        $this->positionIs();
        [$order, $customer] = $this->assignedOrder();

        Sanctum::actingAs($customer);

        $english = $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->json('data.status_label');

        $tamil = $this->getJson("/api/v1/orders/{$order->id}/track", [
            // Quality values and a region, as a real phone sends them.
            'Accept-Language' => 'ta-IN,ta;q=0.9,en;q=0.8',
        ])->assertOk()->json('data.status_label');

        $this->assertSame(__('portal.status.rider_assigned', [], 'en'), $english);
        $this->assertSame(__('portal.status.rider_assigned', [], 'ta'), $tamil);
        $this->assertNotSame($english, $tamil);
    }

    public function test_a_language_we_do_not_have_falls_back_rather_than_failing(): void
    {
        $this->positionIs();
        [$order, $customer] = $this->assignedOrder();

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/orders/{$order->id}/track", [
            'Accept-Language' => 'fr-CA,fr;q=0.9',
        ])
            ->assertOk()
            ->assertJsonPath('data.status_label', __('portal.status.rider_assigned', [], 'en'));
    }
}
