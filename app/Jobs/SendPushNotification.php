<?php

namespace App\Jobs;

use App\Services\Push\PushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sending, off the request.
 *
 * FCM is one HTTP call per device. Doing that inline would add a round trip
 * per rider to the moment a merchant taps "ready" — the one moment in the
 * product where a spinner costs food going cold.
 *
 * Carries a translation key rather than finished text, because the wording
 * depends on the recipient and this job does not know who they are until it
 * runs. Rendering it in the request meant rendering it in the sender's locale
 * and posting English to everybody.
 */
class SendPushNotification implements ShouldQueue
{
    use Queueable;

    /** Three attempts, then give up. A notification is worthless late. */
    public int $tries = 3;

    public int $backoff = 10;

    /**
     * @param  list<int>  $userIds
     * @param  string  $key  translation prefix holding .title and .body
     * @param  array<string, string|int>  $replacements
     * @param  array<string, string>  $data
     */
    public function __construct(
        public array $userIds,
        public string $app,
        public string $key,
        public array $replacements = [],
        public array $data = [],
    ) {}

    public function handle(PushService $push): void
    {
        $push->deliver($this->userIds, $this->app, $this->key, $this->replacements, $this->data);
    }
}
