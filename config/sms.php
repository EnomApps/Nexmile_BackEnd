<?php

return [

    /*
     * 'log'  — writes the message to the Laravel log. Default for local work
     *          and for the Flutter developer before a gateway exists.
     * 'null' — silently discards. Used by the test suite.
     *
     * Add a provider by implementing App\Contracts\SmsSender and registering
     * it in AppServiceProvider.
     */
    'driver' => env('SMS_DRIVER', 'log'),

    'from' => env('SMS_SENDER_ID', 'NEXMLE'),

    'providers' => [

        // Filled in once an account exists. See docs/OTP.md.
        'msg91' => [
            'key' => env('MSG91_AUTH_KEY'),
            'template_id' => env('MSG91_TEMPLATE_ID'),
        ],

        'fast2sms' => [
            'key' => env('FAST2SMS_API_KEY'),
        ],

    ],

];
