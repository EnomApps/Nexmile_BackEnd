<?php

namespace App\Services\Sms;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;

/**
 * Writes the message to the application log instead of sending it.
 *
 * Lets the whole OTP flow work end to end before an SMS account exists —
 * read the code from storage/logs/laravel.log.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        Log::channel(config('logging.default'))->info('SMS (not sent — log driver)', [
            'to' => $phone,
            'message' => $message,
        ]);
    }
}
