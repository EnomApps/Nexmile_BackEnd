<?php

namespace App\Http\Controllers\Web;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\Orders\OrderStatusService;
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
                ->with(['items.options', 'customer', 'statusHistory', 'rider'])
                ->findOrFail($order),
        ]);
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
