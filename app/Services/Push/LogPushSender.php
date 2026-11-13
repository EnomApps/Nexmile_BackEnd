<?php

namespace App\Services\Push;

use App\Contracts\PushSender;
use Illuminate\Support\Facades\Log;

/**
 * The default until FCM credentials exist.
 *
 * Writes what would have been sent, so the dispatch chain can be exercised end
 * to end locally and on staging without a Firebase project — the same choice
 * the SMS driver makes.
 */
class LogPushSender implements PushSender
{
    public function send(
        array $tokens,
        string $title,
        string $body,
        array $data = [],
        ?string $collapseKey = null,
    ): array {
        Log::info('Push (not sent — log driver)', [
            'devices' => count($tokens),
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'collapse_key' => $collapseKey,
        ]);

        // Nothing is dead: a log driver cannot know, and pruning real tokens
        // because the fake could not reach them would be worse than useless.
        return [];
    }

    public function sendData(
        array $tokens,
        array $data,
        ?string $collapseKey = null,
        ?int $ttlSeconds = null,
    ): array {
        /*
         * Logged at info with the payload intact, because this is the only
         * way to see a dispatch wave go out on a machine with no Firebase
         * project — and a rider offer that never arrives is the hardest
         * thing in the product to debug from the outside.
         */
        Log::info('Data push (not sent — log driver)', [
            'devices' => count($tokens),
            'data' => $data,
            'collapse_key' => $collapseKey,
            'ttl_seconds' => $ttlSeconds,
        ]);

        return [];
    }
}
