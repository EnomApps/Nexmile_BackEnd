<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Services\LiveState\DeliveryTimerService;
use App\Services\LiveState\DispatchQueueService;
use App\Services\LiveState\OrderStateService;
use App\Services\LiveState\RiderLocationService;
use App\Support\RedisKeys;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;
use Throwable;

/**
 * Exercised against a real Redis. Skipped rather than failed when none is
 * running, so the suite still passes on a machine without it.
 */
class LiveStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            Redis::connection('live')->ping();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis is not running: '.$e->getMessage());
        }

        Redis::connection('live')->flushdb();
        Redis::connection('dispatch')->flushdb();
    }

    public function test_rider_location_is_stored_and_searchable_by_distance(): void
    {
        $riders = app(RiderLocationService::class);

        // Two points on West Masi Street, Madurai, roughly 200m apart.
        $riders->updateLocation(riderId: 1, zoneId: 5, latitude: 9.9252007, longitude: 78.1197754);
        $riders->updateLocation(riderId: 2, zoneId: 5, latitude: 9.9270000, longitude: 78.1197754);

        $near = $riders->ridersNear(zoneId: 5, latitude: 9.9252007, longitude: 78.1197754, radiusMetres: 1000);

        $this->assertCount(2, $near);
        $this->assertSame(1, $near[0]['rider_id'], 'nearest rider should be returned first');
        // Redis geohashes are 52-bit, so a point round-trips to within ~1m.
        $this->assertLessThan(1, $near[0]['distance_metres']);
        $this->assertGreaterThan(150, $near[1]['distance_metres']);
        $this->assertLessThan(250, $near[1]['distance_metres']);
    }

    public function test_riders_outside_the_radius_are_excluded(): void
    {
        $riders = app(RiderLocationService::class);

        $riders->updateLocation(1, 5, 9.9252007, 78.1197754);
        // ~3km north — beyond a 1km delivery radius.
        $riders->updateLocation(2, 5, 9.9522007, 78.1197754);

        $near = $riders->ridersNear(5, 9.9252007, 78.1197754, 1000);

        $this->assertCount(1, $near);
        $this->assertSame(1, $near[0]['rider_id']);
    }

    public function test_rider_without_a_heartbeat_is_not_dispatchable(): void
    {
        $riders = app(RiderLocationService::class);
        $riders->updateLocation(1, 5, 9.9252007, 78.1197754);

        // Simulates the app going silent: presence lapses, position lingers.
        Redis::connection('live')->del(RedisKeys::riderHeartbeat(1));

        $this->assertFalse($riders->isPresent(1));
        $this->assertSame([], $riders->ridersNear(5, 9.9252007, 78.1197754, 1000));
    }

    public function test_going_offline_removes_the_rider_from_the_zone(): void
    {
        $riders = app(RiderLocationService::class);
        $riders->updateLocation(1, 5, 9.9252007, 78.1197754);

        $riders->goOffline(1, 5);

        $this->assertSame([], $riders->ridersNear(5, 9.9252007, 78.1197754, 1000));
        $this->assertFalse($riders->isPresent(1));
        $this->assertSame('offline', $riders->state(1)['duty_status']);
    }

    public function test_order_state_round_trips_and_tracks_active_orders(): void
    {
        $orders = app(OrderStateService::class);

        $orders->put(42, ['merchant_id' => 7, 'rider_id' => 1, 'eta_minutes' => 12]);
        $orders->setStatus(42, OrderStatus::Preparing);

        $state = $orders->get(42);
        $this->assertSame('7', $state['merchant_id']);
        $this->assertSame('preparing', $state['status']);
        $this->assertSame('12', $state['eta_minutes']);
        $this->assertContains(42, $orders->activeOrderIds());
    }

    public function test_delivered_order_leaves_the_active_set(): void
    {
        $orders = app(OrderStateService::class);
        $orders->put(42, ['merchant_id' => 7]);
        $this->assertContains(42, $orders->activeOrderIds());

        $orders->setStatus(42, OrderStatus::Delivered);

        $this->assertNotContains(42, $orders->activeOrderIds());
        // Kept briefly so an open tracking screen does not blank out.
        $this->assertSame('delivered', $orders->field(42, 'status'));
    }

    public function test_timer_reports_remaining_time_and_expiry(): void
    {
        $timers = app(DeliveryTimerService::class);

        $timers->start(DeliveryTimerService::PREP, 42, 60);

        $remaining = $timers->remaining(DeliveryTimerService::PREP, 42);
        $this->assertNotNull($remaining);
        $this->assertGreaterThan(50, $remaining);
        $this->assertFalse($timers->hasExpired(DeliveryTimerService::PREP, 42));
        $this->assertNotNull($timers->deadline(DeliveryTimerService::PREP, 42));

        $timers->cancel(DeliveryTimerService::PREP, 42);

        $this->assertNull($timers->remaining(DeliveryTimerService::PREP, 42));
        $this->assertTrue($timers->hasExpired(DeliveryTimerService::PREP, 42));
    }

    public function test_a_timer_that_was_never_started_reads_as_expired(): void
    {
        $timers = app(DeliveryTimerService::class);

        // Callers branch on this, so it must not throw or return zero.
        $this->assertTrue($timers->hasExpired(DeliveryTimerService::ARRIVAL, 999));
        $this->assertNull($timers->remaining(DeliveryTimerService::ARRIVAL, 999));
    }

    public function test_dispatch_queue_is_first_in_first_out(): void
    {
        $dispatch = app(DispatchQueueService::class);

        $dispatch->push(5, 101);
        $dispatch->push(5, 102);
        $dispatch->push(5, 103);

        $this->assertSame(3, $dispatch->queueLength(5));
        $this->assertSame(101, $dispatch->pop(5));
        $this->assertSame(102, $dispatch->pop(5));
        $this->assertSame(103, $dispatch->pop(5));
        $this->assertNull($dispatch->pop(5), 'empty queue must return null, not false');
    }

    public function test_an_order_can_be_pulled_out_of_the_queue(): void
    {
        $dispatch = app(DispatchQueueService::class);

        $dispatch->push(5, 101);
        $dispatch->push(5, 102);
        $dispatch->remove(5, 101);

        $this->assertSame(1, $dispatch->queueLength(5));
        $this->assertSame(102, $dispatch->pop(5));
    }

    public function test_only_one_rider_can_hold_an_offer_at_a_time(): void
    {
        $dispatch = app(DispatchQueueService::class);

        $this->assertTrue($dispatch->offer(orderId: 42, riderId: 1));
        // A second worker must not be able to offer the same order elsewhere.
        $this->assertFalse($dispatch->offer(orderId: 42, riderId: 2));

        $this->assertSame(1, $dispatch->offeredTo(42));
        $this->assertGreaterThan(0, $dispatch->offerSecondsLeft(42));

        $dispatch->clearOffer(42);

        $this->assertNull($dispatch->offeredTo(42));
        $this->assertTrue($dispatch->offer(orderId: 42, riderId: 2), 'offer should be reusable once cleared');
    }

    public function test_dispatch_lock_is_exclusive(): void
    {
        $dispatch = app(DispatchQueueService::class);

        $this->assertTrue($dispatch->acquireLock(42));
        $this->assertFalse($dispatch->acquireLock(42));

        $dispatch->releaseLock(42);

        $this->assertTrue($dispatch->acquireLock(42));
    }

    public function test_live_state_and_dispatch_use_separate_databases(): void
    {
        // Clearing the cache must never wipe rider positions or queues.
        app(RiderLocationService::class)->updateLocation(1, 5, 9.9252007, 78.1197754);
        app(DispatchQueueService::class)->push(5, 101);

        Redis::connection('cache')->flushdb();

        $this->assertTrue(app(RiderLocationService::class)->isPresent(1));
        $this->assertSame(1, app(DispatchQueueService::class)->queueLength(5));
    }
}
