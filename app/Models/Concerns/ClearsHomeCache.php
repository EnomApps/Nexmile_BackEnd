<?php

namespace App\Models\Concerns;

use App\Support\HomeCache;

/**
 * Drop the home screen cache whenever this row changes.
 *
 * On the model rather than in the admin controller: a banner can also be
 * switched off by a command, a seeder or a support tool, and an admin who
 * uploads a banner then waits a minute wondering whether it worked has been
 * failed by a cache they never asked for.
 */
trait ClearsHomeCache
{
    protected static function bootClearsHomeCache(): void
    {
        static::saved(fn () => HomeCache::forget());
        static::deleted(fn () => HomeCache::forget());
    }
}
