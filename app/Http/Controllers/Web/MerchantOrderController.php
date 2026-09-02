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

        $base = fn () => $merchant->orders()
            ->with(['items', 'customer'])
            ->where('status', '!=', OrderStatus::PendingPayment->value);

        return view('merchants.orders.index', [
            'merchant' => $merchant,
            // Oldest first: the ticket waiting longest needs attention first.
            'live' => $base()->active()->orderBy('placed_at')->get(),
            'history' => $base()->whereIn('status', [
                OrderStatus::Delivered->value,
                OrderStatus::Cancelled->value,
                OrderStatus::Rejected->value,
            ])->latest('placed_at')->limit(25)->get(),
        ]);
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
     * The newest live order, for the queue page to poll.
     *
     * The page used to detect new orders by reloading itself. Every reload is
     * a fresh document, and a browser blocks audio in a document the user has
     * not yet interacted with — so the alert was reliably silent, because the
     * only tap that ever unlocked it happened in the document before.
     *
     * Polling keeps one document alive, which keeps the audio permission with
     * it, and cuts the delay from up to thirty seconds to ten.
     */
    public function queueStatus(Request $request): JsonResponse
    {
        $newest = $this->merchant($request)->orders()
            ->where('status', '!=', OrderStatus::PendingPayment->value)
            ->active()
            ->max('id');

        return response()->json([
            'newest_order_id' => $newest === null ? 0 : (int) $newest,
        ]);
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

    public function accept(Request $request, int $order): RedirectResponse
    {
        $data = $request->validate([
            'prep_minutes' => ['sometimes', 'nullable', 'integer', 'between:1,120'],
        ]);

        $this->status->accept($this->find($request, $order), $request->user(), $data['prep_minutes'] ?? null);

        return back()->with('status', __('portal.orders.accepted_message'));
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

    public function preparing(Request $request, int $order): RedirectResponse
    {
        $this->status->startPreparing($this->find($request, $order), $request->user());

        return back()->with('status', __('portal.orders.preparing_message'));
    }

    public function ready(Request $request, int $order): RedirectResponse
    {
        $this->status->markReady($this->find($request, $order), $request->user());

        return back()->with('status', __('portal.orders.ready_message'));
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
