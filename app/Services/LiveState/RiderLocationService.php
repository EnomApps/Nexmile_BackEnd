<?php

namespace App\Services\LiveState;

use App\Support\RedisKeys;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Rider positions and presence (EP7, EP9).
 *
 * Positions live in a Redis geo set per zone so "which riders are near this
 * restaurant" is a single indexed command rather than a distance calculation
 * over every rider row in MySQL. GPS pings arrive every few seconds per rider,
 * which no relational table should absorb.
 */
class RiderLocationService
{
    /** A rider is considered present for this long after their last ping. */
    public const HEARTBEAT_TTL = 60;

    protected function redis(): Connection
    {
        return Redis::connection('live');
    }

    /**
     * executeRaw() sends the command untouched, so Laravel's automatic key
     * prefixing does not apply. Raw commands must prefix the key themselves or
     * they read a different key from the one the normal commands wrote.
     */
    protected function prefixed(string $key): string
    {
        return config('database.redis.options.prefix', '').$key;
    }

    /**
     * Record a GPS ping. Also refreshes presence, so a rider whose app is
     * killed drops out of dispatch automatically once the TTL lapses.
     */
    public function updateLocation(int $riderId, int $zoneId, float $latitude, float $longitude): void
    {
        $redis = $this->redis();

        // Redis geo commands take longitude first.
        $redis->geoadd(RedisKeys::zoneRiderGeo($zoneId), $longitude, $latitude, (string) $riderId);

        $redis->hmset(RedisKeys::riderState($riderId), [
            'zone_id' => $zoneId,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'updated_at' => now()->toIso8601String(),
        ]);

        $redis->setex(RedisKeys::riderHeartbeat($riderId), self::HEARTBEAT_TTL, '1');
    }

    /**
     * Riders within $radiusMetres of a point, nearest first.
     *
     * Stale entries are filtered out here rather than by expiring geo members —
     * Redis cannot set a TTL on an individual geo set member, so presence is
     * tracked separately and cross-checked on read.
     *
     * @return array<int, array{rider_id: int, distance_metres: float}>
     */
    public function ridersNear(int $zoneId, float $latitude, float $longitude, int $radiusMetres = 1000, int $limit = 10): array
    {
        $redis = $this->redis();

        /*
         * Sent raw rather than through the client's georadius() helper.
         * predis and phpredis disagree on how modifiers are passed —
         * ['WITHDIST' => true] versus ['WITHDIST'] — and the wrong shape is
         * dropped silently, returning members with no distance attached.
         * The raw command is identical on both.
         *
         * GEORADIUS rather than GEOSEARCH so this also works on the Redis 5.x
         * builds available for local development on Windows.
         */
        $raw = $redis->executeRaw([
            'GEORADIUS',
            $this->prefixed(RedisKeys::zoneRiderGeo($zoneId)),
            (string) $longitude,
            (string) $latitude,
            (string) $radiusMetres,
            'm',
            'WITHDIST',
            'ASC',
            'COUNT',
            (string) ($limit * 2),
        ]);

        if (! is_array($raw)) {
            return [];
        }

        $results = [];

        foreach ($raw as $entry) {
            [$riderId, $distance] = is_array($entry) ? $entry : [$entry, 0.0];

            if (! $this->isPresent((int) $riderId)) {
                continue;
            }

            $results[] = [
                'rider_id' => (int) $riderId,
                'distance_metres' => round((float) $distance, 1),
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    public function isPresent(int $riderId): bool
    {
        return (bool) $this->redis()->exists(RedisKeys::riderHeartbeat($riderId));
    }

    /** @return array<string, string> */
    public function state(int $riderId): array
    {
        return $this->redis()->hgetall(RedisKeys::riderState($riderId)) ?: [];
    }

    public function setDutyStatus(int $riderId, string $status): void
    {
        $this->redis()->hset(RedisKeys::riderState($riderId), 'duty_status', $status);
    }

    /** Remove a rider from dispatch entirely — going offline, or suspended. */
    public function goOffline(int $riderId, int $zoneId): void
    {
        $redis = $this->redis();
        $redis->zrem(RedisKeys::zoneRiderGeo($zoneId), (string) $riderId);
        $redis->del(RedisKeys::riderHeartbeat($riderId));
        $redis->hset(RedisKeys::riderState($riderId), 'duty_status', 'offline');
    }
}
