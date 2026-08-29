<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Close out orders nobody paid for, releasing any Food Rescue portions they
 * were holding. Every five minutes, so a customer who abandons a payment does
 * not keep the last portion out of circulation for long.
 *
 * Needs `* * * * * cd /var/www/nexmile && php artisan schedule:run >> /dev/null 2>&1`
 * in the server's crontab — see docs/PAYMENTS.md.
 */
Schedule::command('nexmile:expire-unpaid')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Uninstalled apps whose tokens FCM still accepts. Each one is a wasted
 * request on every send, so they are swept weekly rather than left to grow.
 */
Schedule::command('nexmile:prune-devices')->weeklyOn(1, '03:30');
