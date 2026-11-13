<?php

namespace App\Jobs;

use App\Services\Push\PushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A data-only payload, off the request.
 *
 * Separate from SendPushNotification because it carries no translation key
 * and must not be retried the same way. A notification three attempts and
 * thirty seconds late is merely late; a ringing order card thirty seconds
 * late rings for an order another rider has already collected.
 *
 * So: one attempt, and a TTL carried through to FCM so the message is dropped
 * rather than held for a phone that is switched off. If a rider's offer
 * genuinely does not arrive, the next wave reaches someone else — which is a
 * better outcome than this one eventually waking a phone with a stale card.
 */
class SendDataPush implements ShouldQueue
{
    use Queueable;

    /**
     * Once. See above — a retry here delivers something that is no longer
     * true, and the dispatch waves are the retry.
     */
    public int $tries = 1;

    /**
     * Abandoned if the queue is so far behind that the payload is worthless.
     * Matches the longest offer window with room to spare.
     */
    public int $timeout = 15;

    /**
     * @param  list<int>  $userIds
     * @param  array<string, string|int>  $data
     */
    public function __construct(
        public array $userIds,
        public string $app,
        public array $data,
        public ?int $ttlSeconds = null,
    ) {}

    public function handle(PushService $push): void
    {
        $push->deliverData($this->userIds, $this->app, $this->data, $this->ttlSeconds);
    }
}
