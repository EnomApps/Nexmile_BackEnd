<?php

namespace App\Http\Controllers\Web\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\OrderStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Order visibility for support (EP5, EP8).
 *
 * Until this existed nobody could look up an order at all. When a customer
 * rings about a late delivery, the first question is "what is happening with
 * NX260808ABCD" and there was no way to answer it.
 */
class AdminOrderController extends Controller
{
    /**
     * How long an order may sit ready with no rider before it is worth
     * someone's attention. At 1 km a rider is minutes away, so ten is already
     * long enough to mean something is wrong.
     */
    public const STALE_MINUTES = 10;

    public function __construct(protected OrderStatusService $status) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string'],
            'search' => ['sometimes', 'nullable', 'string', 'max:50'],
            'view' => ['sometimes', 'in:live,stale,all'],
        ]);

        $view = $filters['view'] ?? 'live';

        $orders = Order::query()
            ->with(['merchant', 'customer', 'rider'])
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('order_number', 'like', "%{$term}%")
                ->orWhere('delivery_contact_phone', 'like', "%{$term}%")))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($view === 'live', fn ($q) => $q->active())
            ->when($view === 'stale', fn ($q) => $this->stale($q))
            ->latest('placed_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.orders.index', [
            'orders' => $orders,
            'statuses' => OrderStatus::cases(),
            'filters' => $filters + ['view' => $view],
            'staleCount' => $this->stale(Order::query())->count(),
        ]);
    }

    public function show(int $order): View
    {
        return view('admin.orders.show', [
            'order' => Order::with([
                'merchant', 'customer', 'rider.user', 'items.options',
                'statusHistory.changedBy', 'payments',
            ])->findOrFail($order),
        ]);
    }

    /**
     * The escape hatch: an order no rider ever took, or one stuck behind a
     * problem nobody else can resolve.
     */
    public function cancel(Request $request, int $order): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        $this->status->cancelByAdmin(Order::findOrFail($order), $request->user(), $data['reason']);

        return back()->with('status', 'Order cancelled.');
    }

    /**
     * Ready to collect, nobody assigned, and waiting too long.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    protected function stale($query)
    {
        return $query
            ->where('status', OrderStatus::ReadyForPickup->value)
            ->whereNull('rider_id')
            ->where('ready_at', '<=', now()->subMinutes(self::STALE_MINUTES));
    }
}
