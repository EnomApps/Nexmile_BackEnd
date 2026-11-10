<?php

namespace App\Services\LiveState;

use App\Support\RedisKeys;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Carbon;
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
     *
     * `$recordedAt` is when the phone took the fix, which is not when we
     * received it. A rider rides through a basement car park, the app
     * buffers, and six fixes arrive at once in the wrong order — stamping
     * them with arrival time would scatter the rider across the customer's
     * map. Returns false when the ping was discarded as stale.
     */
    public function updateLocation(
        int $riderId,
        int $zoneId,
        float $latitude,
        float $longitude,
        ?float $heading = null,
        ?float $speed = null,
        ?Carbon $recordedAt = null,
    ): bool {
        $redis = $this->redis();
        $recordedAt ??= now();

        if ($this->isStale($riderId, $recordedAt)) {
            return false;
        }

        // Redis geo commands take longitude first.
        $redis->geoadd(RedisKeys::zoneRiderGeo($zoneId), $longitude, $latitude, (string) $riderId);

        $redis->hmset(RedisKeys::riderState($riderId), [
            'zone_id' => $zoneId,
            'latitude' => $latitude,
            'longitude' => $longitude,
            /*
             * Which way the bike is pointing, so the marker can be rotated
             * instead of sliding sideways down a one-way street. Stored as
             * an empty string when unknown — Redis hashes hold strings, and
             * a literal 'null' would read back as the text.
             */
            'heading' => $heading ?? '',
            'speed' => $speed ?? '',
            // When the phone took the fix.
            'recorded_at' => $recordedAt->toIso8601String(),
            // When we received it. Kept apart: the gap between the two is
            // the only evidence of a phone with no signal.
            'updated_at' => now()->toIso8601String(),
        ]);

        $redis->setex(RedisKeys::riderHeartbeat($riderId), self::HEARTBEAT_TTL, '1');

        return true;
    }

    /**
     * Is this fix older than the one already stored?
     *
     * Out-of-order delivery is normal on a mobile network. Without this a
     * late-arriving old fix overwrites a newer one and the rider appears to
     * jump back down the road they just came up.
     */
    protected function isStale(int $riderId, Carbon $recordedAt): bool
    {
        // A fix from far enough in the past is refused outright, even with
        // nothing stored: a reconnecting phone replaying a tunnel's worth
        // of buffered positions is not news.
        if ($recordedAt->lt(now()->subSeconds((int) config('tracking.max_ping_age_seconds', 120)))) {
            return true;
        }

        $stored = $this->redis()->hget(RedisKeys::riderState($riderId), 'recorded_at');

        if (! is_string($stored) || $stored === '') {
            return false;
        }

        return rescue(
            fn () => $recordedAt->lt(Carbon::parse($stored)),
            false,
            report: false,
        );
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
