<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The home screen's merchandising cache.
 *
 * One key for banners, cuisines and collection tiles together, so a single
 * edit cannot leave half the screen stale and half fresh.
 */
class HomeCache
{
    public const KEY = 'home.merchandising';

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }

    public static function ttlSeconds(): int
    {
        return (int) config('discovery.home_cache_seconds');
    }
}
