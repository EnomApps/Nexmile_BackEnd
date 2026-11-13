<?php

namespace App\Services\Push;

use App\Contracts\PushSender;

/** Silence. For tests that assert on side effects rather than on the log. */
class NullPushSender implements PushSender
{
    public function send(
        array $tokens,
        string $title,
        string $body,
        array $data = [],
        ?string $collapseKey = null,
    ): array {
        return [];
    }

    public function sendData(
        array $tokens,
        array $data,
        ?string $collapseKey = null,
        ?int $ttlSeconds = null,
    ): array {
        return [];
    }
}
