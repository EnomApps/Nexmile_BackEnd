<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Services\LiveState\DeliveryTimerService;
use App\Services\LiveState\DispatchQueueService;
use App\Services\LiveState\OrderStateService;
use App\Services\LiveState\RiderLocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Exercises every live-state path against the configured Redis so a broken
 * connection is found here rather than by a stranded order in production.
 *
 * Uses ids in a deliberately high range and cleans up after itself.
 */
class RedisHealthCommand extends Command
{
    protected $signature = 'redis:health';

    protected $description = 'Verify Redis connectivity and the live-state keyspace';

    private const TEST_ZONE = 999999;

    private const TEST_RIDER = 999999;

    private const TEST_ORDER = 999999;

    public function handle(
        RiderLocationService $riders,
        OrderStateService $orders,
        DeliveryTimerService $timers,
        DispatchQueueService $dispatch,
    ): int {
        $failed = false;

        foreach (['default', 'cache', 'live', 'dispatch'] as $connection) {
            try {
                $started = microtime(true);
                Redis::connection($connection)->ping();
                $ms = round((microtime(true) - $started) * 1000, 1);
                $db = config("database.redis.{$connection}.database");
                $this->line("  <fg=green>OK</>    {$connection} (db {$db}) — {$ms}ms");
            } catch (Throwable $e) {
                $this->line("  <fg=red>FAIL</>  {$connection} — {$e->getMessage()}");
                $failed = true;
            }
        }

        if ($failed) {
            $this->newLine();
            $this->error('Redis is not reachable. Check REDIS_HOST and that redis-server is running.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Live state:');

        try {
            // Rider geo: place a rider, then find them 200m away.
            $riders->updateLocation(self::TEST_RIDER, self::TEST_ZONE, 9.9252007, 78.1197754);
            $near = $riders->ridersNear(self::TEST_ZONE, 9.9270000, 78.1197754, 1000);
            $found = collect($near)->firstWhere('rider_id', self::TEST_RIDER);
            $this->result('rider geo search', $found !== null,
                $found ? "found at {$found['distance_metres']}m" : 'rider not returned');

            // Order state.
            $orders->put(self::TEST_ORDER, ['merchant_id' => 1, 'rider_id' => self::TEST_RIDER]);
            $orders->setStatus(self::TEST_ORDER, OrderStatus::Preparing);
            $state = $orders->get(self::TEST_ORDER);
            $this->result('order state', ($state['status'] ?? null) === 'preparing',
                'status='.($state['status'] ?? 'missing'));

            // Timer.
            $timers->start(DeliveryTimerService::PREP, self::TEST_ORDER, 30);
            $left = $timers->remaining(DeliveryTimerService::PREP, self::TEST_ORDER);
            $this->result('delivery timer', $left !== null && $left > 0, "{$left}s remaining");

            // Dispatch queue and offer window.
            $dispatch->push(self::TEST_ZONE, self::TEST_ORDER);
            $popped = $dispatch->pop(self::TEST_ZONE);
            $this->result('dispatch queue', $popped === self::TEST_ORDER, "popped {$popped}");

            $first = $dispatch->offer(self::TEST_ORDER, self::TEST_RIDER);
            $second = $dispatch->offer(self::TEST_ORDER, 111111);
            $this->result('offer window is exclusive', $first && ! $second,
                $first && ! $second ? 'second offer correctly rejected' : 'exclusivity broken');
        } catch (Throwable $e) {
            $this->error('  '.$e->getMessage());
            $failed = true;
        } finally {
            $this->cleanUp($riders, $orders, $timers, $dispatch);
        }

        $this->newLine();

        if ($failed) {
            $this->error('Live-state checks failed.');

            return self::FAILURE;
        }

        $this->info('Redis is healthy.');

        return self::SUCCESS;
    }

    private function result(string $label, bool $ok, string $detail): void
    {
        $tag = $ok ? '<fg=green>OK</>  ' : '<fg=red>FAIL</>';
        $this->line("  {$tag}  ".str_pad($label, 26)." {$detail}");
    }

    private function cleanUp(
        RiderLocationService $riders,
        OrderStateService $orders,
        DeliveryTimerService $timers,
        DispatchQueueService $dispatch,
    ): void {
        $riders->goOffline(self::TEST_RIDER, self::TEST_ZONE);
        $orders->forget(self::TEST_ORDER);
        $timers->cancel(DeliveryTimerService::PREP, self::TEST_ORDER);
        $dispatch->clearOffer(self::TEST_ORDER);
        $dispatch->remove(self::TEST_ZONE, self::TEST_ORDER);

        Redis::connection('live')->del(
            \App\Support\RedisKeys::orderState(self::TEST_ORDER),
            \App\Support\RedisKeys::riderState(self::TEST_RIDER),
            \App\Support\RedisKeys::zoneRiderGeo(self::TEST_ZONE),
        );
    }
}
