<?php

namespace App\Http\Controllers\Api\V1\Rider;

use App\Http\Controllers\Controller;
use App\Http\Resources\RiderOrderResource;
use App\Models\Order;
use App\Models\Rider;
use App\Services\Orders\DispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The rider's working day (EP8, EP10).
 *
 * Ready orders sit on an open board; the first rider to accept wins. See
 * config/dispatch.php for why that rather than push-with-timeout.
 */
class OrderController extends Controller
{
    public function __construct(protected DispatchService $dispatch) {}

    /**
     * Orders available to take
     *
     * Nearest restaurant first. Empty when the rider is offline, unverified,
     * already on a delivery, or has not sent a position yet.
     */
    public function available(Request $request): JsonResponse
    {
        $rider = $this->rider($request);

        return response()->json([
            'data' => RiderOrderResource::collection($this->dispatch->board($rider)),
            'meta' => [
                'can_accept' => $this->dispatch->canWork($rider)
                    && $this->dispatch->activeOrderCount($rider) < (int) config('dispatch.max_concurrent_orders_per_rider'),
            ],
        ]);
    }

    /**
     * The rider's own orders
     *
     * `?active=1` is what the working screen polls — the delivery in hand.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        $orders = $this->rider($request)->orders()
            ->with(['merchant', 'items'])
            ->when($filters['active'] ?? false, fn ($q) => $q->active())
            ->latest('assigned_at')
            ->paginate($filters['per_page'] ?? 20);

        return RiderOrderResource::collection($orders)->response();
    }

    /**
     * One order
     */
    public function show(Request $request, int $order): JsonResponse
    {
        return response()->json([
            'data' => new RiderOrderResource($this->assigned($request, $order, ['merchant', 'items.options', 'customer'])),
        ]);
    }

    /**
     * Accept an order
     *
     * Returns 422 if another rider claimed it first — that race is expected
     * on a shared board, not an error worth alarming anyone about.
     */
    public function accept(Request $request, int $order): JsonResponse
    {
        $rider = $this->rider($request);

        $model = Order::with('merchant')->findOrFail($order);

        return response()->json([
            'message' => 'Order accepted. Head to the restaurant.',
            'data' => new RiderOrderResource($this->dispatch->accept($rider, $model)->load('merchant', 'items')),
        ]);
    }

    /**
     * Confirm pickup
     *
     * The restaurant reads out a four-digit code. It is the evidence that the
     * right rider collected the right order, which is what a disputed delivery
     * is settled with.
     */
    public function pickup(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'pickup_code' => ['required', 'string', 'max:8'],
        ]);

        $rider = $this->rider($request);

        $model = $this->dispatch->pickUp($rider, $this->assigned($request, $order), $data['pickup_code']);

        return response()->json([
            'message' => 'Picked up. Deliver to the customer.',
            'data' => new RiderOrderResource($model->load('merchant', 'items')),
        ]);
    }

    /**
     * Hand an order back
     *
     * Puts it back on the board for another rider. Only before collection —
     * once the food is in the bag it has to be delivered.
     */
    public function release(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $rider = $this->rider($request);

        $model = $this->dispatch->release($rider, $this->assigned($request, $order), $data['reason'] ?? null);

        return response()->json([
            'message' => 'Order handed back. It is available to other riders again.',
            'data' => new RiderOrderResource($model->load('merchant', 'items')),
        ]);
    }

    /**
     * Confirm delivery
     */
    public function deliver(Request $request, int $order): JsonResponse
    {
        $rider = $this->rider($request);

        $model = $this->dispatch->deliver($rider, $this->assigned($request, $order));

        return response()->json([
            'message' => 'Delivered. Thanks — you are back online.',
            'data' => new RiderOrderResource($model->load('merchant', 'items')),
        ]);
    }

    /**
     * Scoped through the rider, so an order assigned to someone else is a 404
     * rather than a window onto where a stranger lives.
     *
     * @param  list<string>  $with
     */
    protected function assigned(Request $request, int $order, array $with = ['merchant', 'items']): Order
    {
        return $this->rider($request)->orders()->with($with)->findOrFail($order);
    }

    protected function rider(Request $request): Rider
    {
        $rider = $request->user()->rider;

        abort_if($rider === null, 404, 'No rider profile found for this account.');

        return $rider;
    }
}
