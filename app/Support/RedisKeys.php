<?php

namespace App\Support;

/**
 * Every Redis key used for live state is built here.
 *
 * Keeping the keyspace in one file means it can be reviewed as a whole, and a
 * typo becomes a failing test rather than a silently empty result — a `GET` on
 * a misspelled key returns null rather than an error.
 */
final class RedisKeys
{
    /** Geo set of on-duty rider positions within a zone. */
    public static function zoneRiderGeo(int $zoneId): string
    {
        return "zone:{$zoneId}:riders:geo";
    }

    /** Hash of a rider's live state: duty status, current order, heartbeat. */
    public static function riderState(int $riderId): string
    {
        return "rider:{$riderId}:state";
    }

    /** Presence key with a TTL; its absence means the rider stopped reporting. */
    public static function riderHeartbeat(int $riderId): string
    {
        return "rider:{$riderId}:heartbeat";
    }

    /** Hash of an in-flight order: status, merchant, rider, ETA. */
    public static function orderState(int $orderId): string
    {
        return "order:{$orderId}:state";
    }

    /** Set of order ids currently in flight, for dashboards and recovery. */
    public static function activeOrders(): string
    {
        return 'orders:active';
    }

    /**
     * Countdown timer. The value is the deadline; the TTL is the countdown, so
     * expiry and the remaining time come from the same key.
     */
    public static function timer(string $type, int $orderId): string
    {
        return "timer:{$type}:{$orderId}";
    }

    /** FIFO list of orders waiting for a rider in a zone. */
    public static function dispatchQueue(int $zoneId): string
    {
        return "dispatch:zone:{$zoneId}:queue";
    }

    /** An offer held open for one rider for the acceptance window. */
    public static function dispatchOffer(int $orderId): string
    {
        return "dispatch:offer:{$orderId}";
    }

    /** Guards an order so two dispatch workers cannot assign it at once. */
    public static function dispatchLock(int $orderId): string
    {
        return "dispatch:lock:{$orderId}";
    }
}
