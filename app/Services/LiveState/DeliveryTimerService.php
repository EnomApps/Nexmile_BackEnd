<?php

namespace App\Services\LiveState;

use App\Support\RedisKeys;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Countdown timers for prep, rider arrival and the 15–20 second offer window
 * (EP7, EP9).
 *
 * The TTL is the countdown. Reading the remaining time is a TTL lookup, and
 * expiry needs no sweeper job — the key simply stops existing.
 */
class DeliveryTimerService
{
    public const PREP = 'prep';

    public const ARRIVAL = 'arrival';

    public const OFFER = 'offer';

    protected function redis(): Connection
    {
        return Redis::connection('live');
    }

    /** Start (or restart) a timer. Returns the deadline. */
    public function start(string $type, int $orderId, int $seconds): \DateTimeInterface
    {
        $deadline = now()->addSeconds($seconds);

        $this->redis()->setex(
            RedisKeys::timer($type, $orderId),
            $seconds,
            $deadline->toIso8601String()
        );

        return $deadline;
    }

    /**
     * Seconds left, or null once the timer has expired or was never started.
     * Callers must treat null as "elapsed" — that is the signal to reassign a
     * rider or escalate a late order.
     */
    public function remaining(string $type, int $orderId): ?int
    {
        $ttl = $this->redis()->ttl(RedisKeys::timer($type, $orderId));

        // -2 = no such key, -1 = key exists with no expiry.
        return $ttl > 0 ? $ttl : null;
    }

    public function deadline(string $type, int $orderId): ?string
    {
        return $this->redis()->get(RedisKeys::timer($type, $orderId));
    }

    public function hasExpired(string $type, int $orderId): bool
    {
        return $this->remaining($type, $orderId) === null;
    }

    public function cancel(string $type, int $orderId): void
    {
        $this->redis()->del(RedisKeys::timer($type, $orderId));
    }
}
