<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * Google Maps Platform.
     *
     * This is the *server* key and must be IP-restricted to the EC2 elastic
     * IP. The Flutter apps ship their own keys: a key inside an installed
     * binary is readable by anyone who wants it, so those are defended by
     * platform restrictions (Android package + SHA-1, iOS bundle id), never
     * by secrecy. Sharing one key across server and apps means it can only be
     * restricted as loosely as the loosest consumer.
     *
     * Note that the 1 km radius does NOT come from here — that is Redis GEO,
     * which is free and sub-millisecond. Google is for geocoding a typed
     * address and for road distance and ETA, both of which are billed per
     * call. See docs/MAPS.md.
     */
    'google_maps' => [
        'key' => env('GOOGLE_MAPS_SERVER_KEY'),

        /*
         * A second key, for maps drawn in a browser rather than called from
         * the server.
         *
         * It has to be a different key, not a convenience. The server key is
         * restricted to this machine's IP address, which a browser request
         * can never satisfy; a key that works in a browser is necessarily
         * visible in the page, so it is restricted by HTTP referrer instead
         * and given only the Maps JavaScript API. Putting the server key in
         * a page would publish a key with Directions and Distance Matrix on
         * it to anybody who opened the source.
         *
         * Optional. Unset, the admin rider page shows coordinates and a link
         * out to Google Maps instead of an embedded map, which answers the
         * same question with one more click.
         */
        'browser_key' => env('GOOGLE_MAPS_BROWSER_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
