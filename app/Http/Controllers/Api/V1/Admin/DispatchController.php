<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DispatchStatus;
use App\Enums\OrderStatus;
use App\Enums\RiderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Rider;
use App\Services\Orders\OfferDispatchService;
use App\Services\Orders\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Orders that dispatch could not place, and the way out of them (EP7).
 *
 * This exists because `no_rider_found` is a real outcome and the alternative
 * to a screen is a customer ringing a phone number nobody answers. Three
 * waves of offers went out, nobody took it, and the food is sitting on a
 * shelf — from here somebody can put it back out, hand it to a named rider,
 * or cancel it and refund.
 *
 * Deliberately plain JSON with no pagination games. It is read by whoever is
 * on shift, perhaps twice a day, and it should be the most boring code in the
 * repository.
 */
class DispatchController extends Controller
{
    public function __construct(
        protected OfferDispatchService $offers,
        protected OrderStatusService $status,
    ) {}

    /**
     * Orders nobody is looking for
     *
     * Two kinds, and the second is the one that bites. **Failed** is an order
     * dispatch gave up on, which is honest. **Stuck** is an order that has
     * been searching far longer than its waves should have taken — which
     * almost always means the queue worker is not running, because every
     * timeout in dispatch is a delayed job.
     */
    public function stranded(Request $request): JsonResponse
    {
        $waves = (array) config('dispatch.waves');

        // Every wave, plus a minute of slack for a queue that is merely slow
        // rather than absent.
        $budget = array_sum(array_column($waves, 'seconds')) + 60;

        $orders = Order::query()
            ->with(['merchant:id,business_name,business_phone,city', 'items'])
            ->whereNull('rider_id')
            ->whereNotIn('status', Order::finishedStatuses())
            ->where(fn ($q) => $q
                ->where('dispatch_status', DispatchStatus::NoRiderFound->value)
                ->orWhere(fn ($w) => $w
                    ->where('dispatch_status', DispatchStatus::Searching->value)
                    ->where('dispatch_started_at', '<', now()->subSeconds($budget))))
            ->orderBy('dispatch_started_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $orders->map(fn (Order $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'dispatch_status' => $order->dispatch_status,
                'waves_sent' => $order->dispatch_wave,
                'searching_since' => $order->dispatch_started_at,
                'searching_for_seconds' => $order->dispatch_started_at?->diffInSeconds(now()),
                'gave_up_at' => $order->dispatch_failed_at,
                'restaurant' => $order->merchant?->business_name,
                'restaurant_phone' => $order->merchant?->business_phone,
                'area' => $order->delivery_city,
                'riders_asked' => $order->offers()->count(),
                'order_value' => (float) $order->grand_total,
            ])->all(),

            'meta' => [
                'dispatch_mode' => config('dispatch.mode'),
                'wave_budget_seconds' => $budget,
                /*
                 * Said out loud, because an empty rider pool and a dead queue
                 * worker produce the same symptom and have completely
                 * different fixes.
                 */
                'riders_on_duty' => Rider::where('duty_status', RiderStatus::Available->value)->count(),
            ],
        ]);
    }

    /**
     * Put an order back out to riders
     *
     * Starts again from wave one, with the offers from the failed attempt
     * cleared — riders have moved since, and the rider who declined half an
     * hour ago while mid-delivery is a perfectly good candidate now.
     */
    public function retry(Request $request, int $order): JsonResponse
    {
        $model = Order::with(['merchant', 'items'])->findOrFail($order);

        if ($model->rider_id !== null) {
            throw ValidationException::withMessages([
                'order' => 'A rider already has this order.',
            ]);
        }

        if ($model->status->isTerminal()) {
            throw ValidationException::withMessages([
                'order' => 'This order is already '.str_replace('_', ' ', $model->status->value).'.',
            ]);
        }

        $this->offers->redispatch($model);

        Log::info('Dispatch retried by an admin.', [
            'order_id' => $model->id,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Looking for a rider again.',
            'data' => [
                'dispatch_status' => $model->refresh()->dispatch_status,
                'dispatch_wave' => $model->dispatch_wave,
            ],
        ]);
    }

    /**
     * Give an order to a named rider
     *
     * The last resort, and the reason the stranded list is worth having: a
     * phone call to a rider who is standing outside the restaurant anyway,
     * then this.
     *
     * It skips every eligibility check except the ones that would be fraud or
     * a legal problem — a rider whose licence has expired cannot be handed a
     * delivery by anybody, including an admin.
     */
    public function assign(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'rider_id' => ['required', 'integer', 'exists:riders,id'],
        ]);

        $model = Order::with(['merchant', 'items'])->findOrFail($order);
        $rider = Rider::with('user')->findOrFail($data['rider_id']);

        if ($model->rider_id !== null) {
            throw ValidationException::withMessages([
                'order' => 'A rider already has this order.',
            ]);
        }

        if ($model->status->isTerminal()) {
            throw ValidationException::withMessages([
                'order' => 'This order is already '.str_replace('_', ' ', $model->status->value).'.',
            ]);
        }

        if (! $rider->canAcceptOrders()) {
            throw ValidationException::withMessages([
                'rider_id' => $rider->offlineReason() ?? 'This rider cannot take deliveries.',
            ]);
        }

        if ($rider->user_id === $model->user_id) {
            throw ValidationException::withMessages([
                'rider_id' => 'A rider cannot deliver their own order.',
            ]);
        }

        $model->forceFill([
            'rider_id' => $rider->id,
            'dispatch_status' => DispatchStatus::Assigned->value,
            'assigned_at' => now(),
            'dispatch_failed_at' => null,
        ])->save();

        $rider->forceFill(['duty_status' => RiderStatus::OnOrder->value])->save();

        // Anyone still holding a card for it stops ringing.
        $this->offers->cancelOutstanding($model, 'assigned_by_ops');

        /*
         * Status only moves if the food is ready. Otherwise the kitchen keeps
         * the order — same rule as a rider accepting an offer, for the same
         * reason: taking "mark ready" away from a merchant who is still
         * cooking breaks the kitchen screen.
         */
        if ($model->status === OrderStatus::ReadyForPickup) {
            $this->status->markAssigned($model, $rider->user);
        }

        Log::warning('Order assigned to a rider by an admin.', [
            'order_id' => $model->id,
            'rider_id' => $rider->id,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Assigned to '.$rider->full_name.'.',
            'data' => [
                'order_id' => $model->id,
                'rider_id' => $rider->id,
                'status' => $model->refresh()->status,
            ],
        ]);
    }
}
