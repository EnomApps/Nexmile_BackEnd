<?php

namespace App\Services\Auth;

use App\Contracts\SmsSender;
use App\Mail\OtpCodeMail;
use Illuminate\Support\Facades\Mail;

/**
 * Decides how a code reaches the user.
 *
 * The channel follows the identifier: an email address is emailed, a mobile
 * number is texted. Switching the apps from email login to SMS login therefore
 * needs no change here and no config flag — the client simply starts sending a
 * phone number instead.
 */
class OtpDelivery
{
    public const EMAIL = 'email';

    public const SMS = 'sms';

    public function __construct(private readonly SmsSender $sms) {}

    /** Which channel an identifier implies. */
    public static function channelFor(string $identifier): string
    {
        return filter_var($identifier, FILTER_VALIDATE_EMAIL) ? self::EMAIL : self::SMS;
    }

    public function send(string $identifier, string $channel, string $code): void
    {
        $minutes = (int) ceil(config('otp.ttl_seconds') / 60);

        if ($channel === self::EMAIL) {
            Mail::to($identifier)->send(new OtpCodeMail($code, $minutes));

            return;
        }

        /*
         * Kept to a single variable and fixed wording. Indian operators reject
         * any message that does not match its DLT-approved template exactly,
         * so the text here and the registered template must stay in step.
         */
        $this->sms->send($identifier, "{$code} is your Nexmile verification code. Valid for {$minutes} minutes. Do not share it with anyone.");
    }
}
