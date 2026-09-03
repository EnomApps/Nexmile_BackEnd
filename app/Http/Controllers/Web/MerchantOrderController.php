<?php

namespace App\Http\Controllers\Web;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\Orders\InvoiceService;
use App\Services\Orders\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Order management in the merchant portal (EP5, EP8).
 */
class MerchantOrderController extends Controller
{
    public function __construct(protected OrderStatusService $status) {}

    public function index(Request $request): View
    {
        $merchant = $this->merchant($request);

        return view('merchants.orders.index', [
            'merchant' => $merchant,
            ...$this->queue($merchant),
            // The page starts knowing its own fingerprint, so the first poll
            // ten seconds later is answered with "nothing changed".
            ...$this->signature($merchant),
        ]);
    }

    /**
     * The two lists the queue page shows.
     *
     * @return array{live: Collection, history: Collection}
     */
    protected function queue(Merchant $merchant): array
    {
        $base = fn () => $merchant->orders()
            ->with(['items', 'customer'])
            ->where('status', '!=', OrderStatus::PendingPayment->value);

        return [
            // Oldest first: the ticket waiting longest needs attention first.
            'live' => $base()->active()->orderBy('placed_at')->get(),
            'history' => $base()->whereIn('status', [
                OrderStatus::Delivered->value,
                OrderStatus::Cancelled->value,
                OrderStatus::Rejected->value,
            ])->latest('placed_at')->limit(25)->get(),
        ];
    }

    /**
     * A fingerprint of everything the merchant can currently see.
     *
     * One aggregate query, no models built. This runs every ten seconds for
     * every kitchen with the page open, and most of those ten-second windows
     * contain no change at all — loading both lists to discover that would
     * cost more than the reload this whole change removes.
     *
     * Counting per status rather than taking the latest timestamp is what
     * catches an order accepted and started inside the same second: the times
     * collide, the distribution does not.
     */
    protected function signature(Merchant $merchant): array
    {
        // Aliased away from `status` so the model's enum cast does not apply
        // to what is a grouping key here, not an order.
        $rows = $merchant->orders()
            ->where('status', '!=', OrderStatus::PendingPayment->value)
            ->groupBy('status')
            ->orderBy('status')
            ->selectRaw('status as state, COUNT(*) as tally, MAX(id) as newest, MAX(updated_at) as touched')
            ->get();

        $live = $rows->reject(fn ($r) => OrderStatus::from($r->state)->isTerminal());

        return [
            'signature' => md5($rows
                ->map(fn ($r) => $r->state.':'.$r->tally.':'.$r->newest.':'.$r->touched)
                ->join('|')),
            'newest_order_id' => (int) ($live->max('newest') ?? 0),
        ];
    }

    /**
     * The queue as JSON, for the page to swap in without reloading.
     *
     * The markup is built only when the caller's fingerprint is stale, so a
     * quiet kitchen costs one query and a few bytes per tick.
     */
    protected function queuePayload(Merchant $merchant, ?string $known = null): array
    {
        $state = $this->signature($merchant);
        $changed = $state['signature'] !== $known;

        return [
            ...$state,
            'changed' => $changed,
            'html' => $changed
                ? view('merchants.orders.partials.lists', $this->queue($merchant))->render()
                : null,
        ];
    }

    public function show(Request $request, int $order): View
    {
        $merchant = $this->merchant($request);

        return view('merchants.orders.show', [
            'merchant' => $merchant,
            'order' => $merchant->orders()
                // rider.user, not just rider: the page shows the rider's phone,
                // and that lives on the user record.
                ->with(['items.options', 'customer', 'statusHistory', 'rider.user'])
                ->findOrFail($order),
        ]);
    }

    /**
     * Just the status, for the detail page to poll.
     *
     * Everything that moves an order after "ready" is done by someone else —
     * a rider accepts it, collects it, delivers it — so a merchant watching
     * the page sees nothing until they navigate away and back. Reloading the
     * whole page on a timer would do it, but this is a few bytes and lets the
     * page reload only when something has actually changed.
     *
     * The poor relation of a push notification, and replaced by one when that
     * exists. Until then a merchant should not have to press Back to find out
     * their food has been collected.
     */
    public function status(Request $request, int $order): JsonResponse
    {
        $model = $this->merchant($request)->orders()->findOrFail($order);

        return response()->json([
            'status' => $model->status,
            // The rider arriving is a change worth reloading for even though
            // the status itself does not move.
            'rider_id' => $model->rider_id,
        ]);
    }

    /**
     * The queue, for the page to poll.
     *
     * The page used to detect new orders by reloading itself, then kept
     * reloading to show them. Every reload is a fresh document, and a browser
     * blocks audio in a document the user has not yet interacted with — so
     * the alert was reliably silent after the first one, because the tap that
     * unlocked it had happened in the document before.
     *
     * One document that never reloads keeps the audio permission for the
     * whole shift, and the markup only crosses the wire when something has
     * actually changed.
     */
    public function queueStatus(Request $request): JsonResponse
    {
        $known = $request->query('known');

        return response()->json($this->queuePayload(
            $this->merchant($request),
            is_string($known) ? $known : null,
        ));
    }

    /**
     * The tax invoice for an order.
     *
     * A GST-registered restaurant has to be able to produce one, and every
     * figure it needs was snapshotted when the order was placed.
     */
    public function invoice(Request $request, int $order, InvoiceService $invoices): View
    {
        $model = $this->merchant($request)->orders()
            ->with(['items.options', 'customer', 'merchant', 'payments'])
            ->findOrFail($order);

        return view('invoice', ['invoice' => $invoices->build($model)]);
    }

    public function accept(Request $request, int $order): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'prep_minutes' => ['sometimes', 'nullable', 'integer', 'between:1,120'],
        ]);

        $this->status->accept($this->find($request, $order), $request->user(), $data['prep_minutes'] ?? null);

        return $this->respond($request, __('portal.orders.accepted_message'));
    }

    public function reject(Request $request, int $order): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ], [
            'reason.min' => __('portal.orders.reject_hint'),
        ]);

        $this->status->reject($this->find($request, $order), $request->user(), $data['reason']);

        return back()->with('status', __('portal.orders.rejected_message'));
    }

    /**
     * Cancel an order already accepted — a gas failure, a missing ingredient.
     * Different act from rejecting, which only applies before accepting.
     */
    public function cancel(Request $request, int $order): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ], [
            'reason.min' => __('portal.orders.cancel_hint'),
        ]);

        $this->status->cancelByMerchant($this->find($request, $order), $request->user(), $data['reason']);

        return redirect()->route('merchants.orders.index')
            ->with('status', __('portal.orders.cancelled_message'));
    }

    public function preparing(Request $request, int $order): RedirectResponse|JsonResponse
    {
        $this->status->startPreparing($this->find($request, $order), $request->user());

        return $this->respond($request, __('portal.orders.preparing_message'));
    }

    public function ready(Request $request, int $order): RedirectResponse|JsonResponse
    {
        $this->status->markReady($this->find($request, $order), $request->user());

        return $this->respond($request, __('portal.orders.ready_message'));
    }

    /**
     * A redirect for a plain form post, the updated queue for a fetch.
     *
     * The forms stay real forms, so the portal still works with no JavaScript
     * — a shop on a browser that fails to run the script gets the old
     * reload-and-redirect, not a dead button.
     */
    protected function respond(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if (! $request->expectsJson()) {
            return back()->with('status', $message);
        }

        return response()->json([
            'message' => $message,
            // Always fresh: the action is why the caller asked.
            ...$this->queuePayload($this->merchant($request)),
        ]);
    }

    protected function find(Request $request, int $order): Order
    {
        return $this->merchant($request)->orders()->findOrFail($order);
    }

    protected function merchant(Request $request): Merchant
    {
        $merchant = $request->user()->merchant;

        abort_if($merchant === null, 404);

        return $merchant;
    }
}
