<?php

namespace App\Services\Sms;

use App\Contracts\SmsSender;

/**
 * Discards messages. Used by the test suite so runs produce no log noise.
 */
class NullSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        // Intentionally does nothing.
    }
}
