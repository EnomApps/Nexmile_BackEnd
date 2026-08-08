<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\LiveState\OrderStateService;
use App\Services\LiveState\RiderLocationService;
use App\Services\Orders\InvoiceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The customer's own orders (EP5, EP9).
 *
 * Scoped to the authenticated user throughout: an order id belonging to
 * someone else is a 404, never a window onto what a stranger ate and where
 * they live.
 */
class OrderController extends Controller
{
    public function __construct(protected OrderStateService $liveState) {}

    /**
     * Order history
     *
     * Newest first. `?active=1` returns only orders still in progress, which
     * is what a home screen banner needs.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        $orders = $request->user()->orders()
            ->with(['items', 'merchant'])
            ->when($filters['active'] ?? false, fn ($q) => $q->active())
            ->latest('created_at')
            ->paginate($filters['per_page'] ?? 20);

        return OrderResource::collection($orders)->response();
    }

    /**
     * One order
     */
    public function show(Request $request, int $order): JsonResponse
    {
        return response()->json([
            'data' => new OrderResource($this->find($request, $order, [
                'items.options', 'merchant', 'statusHistory', 'rider.user',
            ])),
        ]);
    }

    /**
     * Live tracking
     *
     * Deliberately small and cheap: this is polled every few seconds while an
     * order is in flight, so it reads Redis and falls back to MySQL rather
     * than loading the whole order each time.
     */
    public function track(Request $request, int $order): JsonResponse
    {
        $model = $this->find($request, $order);

        $live = rescue(fn () => $this->liveState->get($model->id), [], report: false);

        $status = OrderStatus::tryFrom($live['status'] ?? '') ?? $model->status;

        return response()->json([
            'data' => [
                'order_number' => $model->order_number,
                'status' => $status,
                'status_label' => __('portal.status.'.$status->value),
                'estimated_prep_minutes' => $model->estimated_prep_minutes,
                'accepted_at' => $model->accepted_at,
                'ready_at' => $model->ready_at,
                'picked_up_at' => $model->picked_up_at,
                'delivered_at' => $model->delivered_at,
                // Shown to the rider at handover; the customer sees it too so
                // they can check the right order arrived.
                'pickup_code' => $model->pickup_code,
                'cancellation_reason' => $model->cancellation_reason,
                'rider' => $model->rider_id ? [
                    'name' => $model->rider?->full_name,
                    'vehicle_number' => $model->rider?->vehicle_number,
                    'phone' => $model->rider?->user?->phone,
                    /*
                     * Live position, and only while the rider is actually
                     * carrying this order. Once it is delivered their movements
                     * are their own business, not the customer's.
                     */
                    'location' => $this->riderPosition($model),
                ] : null,
            ],
        ]);
    }

    /**
     * Tax invoice
     *
     * A printable page rather than JSON: this exists to be saved as a PDF or
     * handed to an accountant, and nobody does either with an API response.
     */
    public function invoice(Request $request, int $order, InvoiceService $invoices): View
    {
        $model = $this->find($request, $order, ['items.options', 'merchant', 'customer', 'payments']);

        return view('invoice', ['invoice' => $invoices->build($model)]);
    }

    /**
     * Cancel an order
     *
     * Only before the restaurant accepts. Once they have, the kitchen may
     * already have started, and cancelling would waste food someone is part
     * way through cooking.
     */
    public function cancel(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $model = $this->find($request, $order);

        if (! in_array($model->status->value, config('checkout.customer_cancellable_statuses'), true)) {
            return response()->json([
                'message' => $model->status === OrderStatus::Cancelled
                    ? 'This order is already cancelled.'
                    : 'The restaurant has already started your order. Call them if something is wrong.',
            ], 422);
        }

        DB::transaction(function () use ($model, $request, $data) {
            $from = $model->status;

            $model->update([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => 'customer',
                'cancellation_reason' => $data['reason'] ?? null,
                // Cancelled before anyone started work, so nothing is charged.
                'cancellation_fee' => 0,
            ]);

            $model->statusHistory()->create([
                'from_status' => $from,
                'to_status' => OrderStatus::Cancelled,
                'changed_by_user_id' => $request->user()->id,
                'note' => $data['reason'] ?? null,
                'created_at' => now(),
            ]);
        });

        rescue(fn () => $this->liveState->setStatus($model->id, OrderStatus::Cancelled), report: true);

        return response()->json([
            'message' => 'Order cancelled.',
            'data' => new OrderResource($model->fresh('items')),
        ]);
    }

    /**
     * Where the rider is, if they are still carrying this order.
     *
     * @return array{latitude: float, longitude: float, updated_at: string|null}|null
     */
    protected function riderPosition(Order $order): ?array
    {
        $inFlight = in_array($order->status, [
            OrderStatus::RiderAssigned,
            OrderStatus::PickedUp,
        ], true);

        if (! $inFlight || $order->rider_id === null) {
            return null;
        }

        $state = rescue(
            fn () => app(RiderLocationService::class)->state($order->rider_id),
            [],
            report: false,
        );

        if (! isset($state['latitude'], $state['longitude'])) {
            return null;
        }

        return [
            'latitude' => (float) $state['latitude'],
            'longitude' => (float) $state['longitude'],
            'updated_at' => $state['updated_at'] ?? null,
        ];
    }

    /**
     * @param  list<string>  $with
     */
    protected function find(Request $request, int $order, array $with = ['items']): Order
    {
        return $request->user()->orders()->with($with)->findOrFail($order);
    }
}
