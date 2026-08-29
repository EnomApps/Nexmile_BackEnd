<?php

return [

    /*
     * Leave empty for the log driver, which writes what would have been sent.
     * The dispatch chain then works end to end without a Firebase project —
     * the same choice SMS and payments make.
     */
    'driver' => env('PUSH_DRIVER'),

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),

        /*
         * Absolute path to the service account JSON, outside the repository.
         * It holds a private key that can send notifications to every install
         * of both apps.
         */
        'credentials' => env('FCM_CREDENTIALS'),
    ],

    /*
     * Android needs a channel to attach a sound and importance to, and it must
     * match the channel the app creates on first run. A mismatch is silent:
     * the notification arrives with no sound and nobody knows why.
     */
    'android_channel' => env('FCM_ANDROID_CHANNEL', 'nexmile_orders'),

    'timeout_seconds' => 10,

    /*
     * Installs that have not checked in for this long are treated as gone.
     * A phone that was replaced still holds a token FCM will accept for a
     * while, and sending to it is a notification nobody receives.
     */
    'prune_after_days' => 120,

];
