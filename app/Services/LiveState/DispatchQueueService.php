<?php

namespace App\Services\LiveState;

use App\Support\RedisKeys;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Dispatch queues and rider offer windows (EP7).
 *
 * This ticket provides the transport only. The matching and ranking logic
 * lives in the Python dispatch service, which reads and writes the same keys
 * on the `dispatch` database.
 */
class DispatchQueueService
{
    /** How long a rider has to accept before the order is offered onward. */
    public const OFFER_WINDOW = 20;

    /** Safety net so a crashed worker cannot hold an order forever. */
    public const LOCK_TTL = 30;

    protected function redis(): Connection
    {
        return Redis::connection('dispatch');
    }

    /** Queue an order for rider assignment. */
    public function push(int $zoneId, int $orderId): void
    {
        $this->redis()->lpush(RedisKeys::dispatchQueue($zoneId), $orderId);
    }

    /** Take the oldest waiting order, or null when the queue is empty. */
    public function pop(int $zoneId): ?int
    {
        $orderId = $this->redis()->rpop(RedisKeys::dispatchQueue($zoneId));

        return $orderId !== null && $orderId !== false ? (int) $orderId : null;
    }

    public function queueLength(int $zoneId): int
    {
        return (int) $this->redis()->llen(RedisKeys::dispatchQueue($zoneId));
    }

    /** @return array<int, int> Queued order ids, oldest last. */
    public function peek(int $zoneId, int $limit = 20): array
    {
        return array_map('intval', $this->redis()->lrange(RedisKeys::dispatchQueue($zoneId), 0, $limit - 1) ?: []);
    }

    public function remove(int $zoneId, int $orderId): void
    {
        $this->redis()->lrem(RedisKeys::dispatchQueue($zoneId), 0, $orderId);
    }

    /**
     * Offer an order to one rider for the acceptance window.
     *
     * Returns false when an offer is already outstanding, so a second worker
     * cannot offer the same order to a different rider at the same time.
     */
    public function offer(int $orderId, int $riderId, int $seconds = self::OFFER_WINDOW): bool
    {
        $created = $this->redis()->set(
            RedisKeys::dispatchOffer($orderId),
            $riderId,
            'EX',
            $seconds,
            'NX'
        );

        return (bool) $created;
    }

    /** The rider currently holding an offer, if the window is still open. */
    public function offeredTo(int $orderId): ?int
    {
        $riderId = $this->redis()->get(RedisKeys::dispatchOffer($orderId));

        return $riderId !== null && $riderId !== false ? (int) $riderId : null;
    }

    public function offerSecondsLeft(int $orderId): ?int
    {
        $ttl = $this->redis()->ttl(RedisKeys::dispatchOffer($orderId));

        return $ttl > 0 ? $ttl : null;
    }

    /** Clear the offer — accepted, declined, or the window lapsed. */
    public function clearOffer(int $orderId): void
    {
        $this->redis()->del(RedisKeys::dispatchOffer($orderId));
    }

    /**
     * Claim exclusive handling of an order. Returns false if another worker
     * already holds it.
     */
    public function acquireLock(int $orderId, int $seconds = self::LOCK_TTL): bool
    {
        return (bool) $this->redis()->set(
            RedisKeys::dispatchLock($orderId),
            (string) getmypid(),
            'EX',
            $seconds,
            'NX'
        );
    }

    public function releaseLock(int $orderId): void
    {
        $this->redis()->del(RedisKeys::dispatchLock($orderId));
    }
}
