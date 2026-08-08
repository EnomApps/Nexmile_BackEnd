<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\Concerns\ResolvesMerchant;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Orders\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Merchant order management (EP5, EP8).
 *
 * Read endpoints are polled by the kitchen screen; writes go through
 * OrderStatusService so history and live state stay consistent.
 */
class OrderController extends Controller
{
    use ResolvesMerchant;

    public function __construct(protected OrderStatusService $status) {}

    /**
     * List orders
     *
     * Defaults to live orders — the kitchen queue. Pass `status` for a
     * specific state or `history=1` for completed ones.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string', 'in:'.implode(',', OrderStatus::values())],
            'history' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $query = $this->merchant($request)->orders()
            ->with(['items', 'customer'])
            /*
             * Unpaid orders are not the merchant's business: the customer may
             * still be on the payment screen, and showing them would put
             * tickets in the kitchen for money that never arrives.
             */
            ->where('status', '!=', OrderStatus::PendingPayment->value);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        } elseif ($filters['history'] ?? false) {
            $query->whereIn('status', [
                OrderStatus::Delivered->value,
                OrderStatus::Cancelled->value,
                OrderStatus::Rejected->value,
            ]);
        } else {
            $query->active();
        }

        // Oldest first for the live queue: the ticket waiting longest is the
        // one the kitchen should see at the top.
        $orders = $query
            ->orderBy('placed_at', ($filters['history'] ?? false) ? 'desc' : 'asc')
            ->paginate($filters['per_page'] ?? 25);

        return OrderResource::collection($orders)->response();
    }

    /**
     * Show one order
     */
    public function show(Request $request, int $order): JsonResponse
    {
        $model = $this->find($request, $order, ['items.options', 'customer', 'statusHistory', 'rider']);

        return response()->json(['data' => new OrderResource($model)]);
    }

    /**
     * Accept an order
     */
    public function accept(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'prep_minutes' => ['sometimes', 'integer', 'between:1,120'],
        ]);

        $model = $this->status->accept(
            $this->find($request, $order),
            $request->user(),
            $data['prep_minutes'] ?? null,
        );

        return $this->respond($model, 'Order accepted.');
    }

    /**
     * Reject an order
     *
     * The reason reaches the customer, so "no" is not enough.
     */
    public function reject(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ], [
            'reason.min' => 'Tell the customer why — they see this message.',
        ]);

        $model = $this->status->reject($this->find($request, $order), $request->user(), $data['reason']);

        return $this->respond($model, 'Order rejected. The customer will be refunded in full.');
    }

    /**
     * Cancel an order already accepted
     *
     * Different act from rejecting: the kitchen said yes and then something
     * went wrong. Without it there is no way out of an order that cannot be
     * cooked.
     */
    public function cancel(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ], [
            'reason.min' => 'Tell the customer why — they see this message.',
        ]);

        $model = $this->status->cancelByMerchant($this->find($request, $order), $request->user(), $data['reason']);

        return $this->respond($model, 'Order cancelled. The customer has been told why.');
    }

    /**
     * Start preparing an order
     */
    public function preparing(Request $request, int $order): JsonResponse
    {
        $model = $this->status->startPreparing($this->find($request, $order), $request->user());

        return $this->respond($model, 'Order marked as preparing.');
    }

    /**
     * Mark an order ready for pickup
     *
     * For a delivery order this is what puts it into the dispatch queue.
     */
    public function ready(Request $request, int $order): JsonResponse
    {
        $model = $this->status->markReady($this->find($request, $order), $request->user());

        return $this->respond($model, 'Order is ready for pickup.');
    }

    /**
     * @param  list<string>  $with
     */
    protected function find(Request $request, int $order, array $with = ['items']): Order
    {
        return $this->merchant($request)->orders()->with($with)->findOrFail($order);
    }

    protected function respond(Order $order, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => new OrderResource($order->load('items')),
        ]);
    }
}
