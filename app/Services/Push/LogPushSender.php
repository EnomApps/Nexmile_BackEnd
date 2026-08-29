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
    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        Log::info('Push (not sent — log driver)', [
            'devices' => count($tokens),
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        // Nothing is dead: a log driver cannot know, and pruning real tokens
        // because the fake could not reach them would be worse than useless.
        return [];
    }
}
