<?php

return [

    /*
    |--------------------------------------------------------------------------
    | One-time passwords
    |--------------------------------------------------------------------------
    */

    'length' => 6,

    // Short enough to limit a stolen code's usefulness, long enough for a
    // slow SMS to arrive.
    'ttl_seconds' => 300,

    // Wrong guesses before the code is burned. 6 digits with 5 attempts leaves
    // a 1-in-200,000 chance of a blind guess.
    'max_attempts' => 5,

    // A user must wait this long before asking for another code.
    'resend_cooldown_seconds' => 60,

    // Codes requested per phone number per hour.
    'max_per_hour' => 5,

    /*
     * Lets QA and the Flutter developer log in without a live SMS gateway.
     * Guarded so it can never apply in production — see OtpService.
     */
    'fixed_code' => env('OTP_FIXED_CODE'),

    /*
    |--------------------------------------------------------------------------
    | Tokens
    |--------------------------------------------------------------------------
    */

    'access_token_ttl_minutes' => (int) env('ACCESS_TOKEN_TTL_MINUTES', 60),

    'refresh_token_ttl_days' => (int) env('REFRESH_TOKEN_TTL_DAYS', 30),

];
