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
 */
class SendPushNotification implements ShouldQueue
{
    use Queueable;

    /** Three attempts, then give up. A notification is worthless late. */
    public int $tries = 3;

    public int $backoff = 10;

    /**
     * @param  list<int>  $userIds
     * @param  array<string, string>  $data
     */
    public function __construct(
        public array $userIds,
        public string $app,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    public function handle(PushService $push): void
    {
        $push->deliver($this->userIds, $this->app, $this->title, $this->body, $this->data);
    }
}
