<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Orders\OfferDispatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The end of one wave of offers.
 *
 * This job is the clock for the whole of offer dispatch. Lapse the cards
 * nobody answered, then widen the search or hand the order to a person. Every
 * timeout in the feature is one of these, scheduled when the wave went out.
 *
 * Which means: **with no queue worker running, dispatch stops after the first
 * wave** and the order sits in `searching` with nobody looking at it. There is
 * no scheduler fallback and no sweeper, deliberately — a second mechanism that
 * quietly covers for a missing worker is a second mechanism to be wrong about.
 * `config/dispatch.php` keeps the board as the default mode for this reason.
 *
 * Carries an order id rather than the model. A queued model is re-fetched on
 * unserialize anyway, and a serialized one fails the whole job if the row was
 * deleted in between rather than simply finding nothing to do.
 */
class CloseDispatchWave implements ShouldQueue
{
    use Queueable;

    /**
     * Twice. The work is idempotent — it is guarded on the wave number, so a
     * retry after a database blip closes the same wave once — but a single
     * attempt means one dropped connection strands an order permanently.
     */
    public int $tries = 2;

    public int $backoff = 5;

    public function __construct(public int $orderId, public int $wave) {}

    public function handle(OfferDispatchService $dispatch): void
    {
        $order = Order::with(['merchant', 'items'])->find($this->orderId);

        if ($order === null) {
            return;
        }

        $dispatch->closeWave($order, $this->wave);
    }

    /**
     * The last line of defence for an order nobody can see.
     *
     * If this job has failed both attempts, the order is in `searching` and
     * nothing is going to move it. Logged at error with the order number in
     * it, because that is the string somebody will search for when a customer
     * rings up about food that never came.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('Dispatch wave could not be closed; an order may be stranded.', [
            'order_id' => $this->orderId,
            'wave' => $this->wave,
            'error' => $exception?->getMessage(),
        ]);
    }
}
