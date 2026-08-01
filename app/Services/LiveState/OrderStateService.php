<?php

namespace App\Services\LiveState;

use App\Enums\OrderStatus;
use App\Support\RedisKeys;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Live state for in-flight orders (EP9).
 *
 * MySQL remains the source of truth. This is a hot copy of the handful of
 * fields that customer tracking, the merchant dashboard and dispatch read
 * every few seconds, so those polls never touch the orders table.
 *
 * Everything here is rebuildable from MySQL, so losing Redis costs latency,
 * not data.
 */
class OrderStateService
{
    /** Long enough to outlive any sane order, short enough to self-clean. */
    public const TTL = 6 * 3600;

    protected function redis(): Connection
    {
        return Redis::connection('live');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function put(int $orderId, array $attributes): void
    {
        $redis = $this->redis();
        $key = RedisKeys::orderState($orderId);

        $payload = [];
        foreach ($attributes as $field => $value) {
            // Redis hashes store strings; nulls would arrive back as "".
            if ($value !== null) {
                $payload[$field] = is_bool($value) ? (int) $value : (string) $value;
            }
        }

        if ($payload !== []) {
            $redis->hmset($key, $payload);
        }

        $redis->expire($key, self::TTL);
        $redis->sadd(RedisKeys::activeOrders(), $orderId);
    }

    public function setStatus(int $orderId, OrderStatus $status): void
    {
        $this->put($orderId, [
            'status' => $status->value,
            'status_changed_at' => now()->toIso8601String(),
        ]);

        if ($status->isTerminal()) {
            $this->forget($orderId);
        }
    }

    /** @return array<string, string> */
    public function get(int $orderId): array
    {
        return $this->redis()->hgetall(RedisKeys::orderState($orderId)) ?: [];
    }

    public function field(int $orderId, string $field): ?string
    {
        return $this->redis()->hget(RedisKeys::orderState($orderId), $field);
    }

    /** @return array<int, int> */
    public function activeOrderIds(): array
    {
        return array_map('intval', $this->redis()->smembers(RedisKeys::activeOrders()) ?: []);
    }

    /**
     * Drop an order from live state once it is delivered or cancelled. The hash
     * is kept briefly so a tracking screen already open does not blank out.
     */
    public function forget(int $orderId): void
    {
        $redis = $this->redis();
        $redis->srem(RedisKeys::activeOrders(), $orderId);
        $redis->expire(RedisKeys::orderState($orderId), 300);
    }
}
