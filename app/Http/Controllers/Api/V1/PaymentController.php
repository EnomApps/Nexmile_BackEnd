<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paying for an order (EP6).
 *
 * Two calls: open a payment, then confirm what the SDK returned. Both are
 * scoped to the signed-in customer, so another person's order id is a 404.
 */
class PaymentController extends Controller
{
    public function __construct(protected PaymentService $payments) {}

    /**
     * Start a payment
     *
     * Returns what the Razorpay SDK needs. Call this after checkout returns an
     * order in `pending_payment`, and again if the customer abandons the sheet
     * and comes back — reopening is safe.
     */
    public function start(Request $request, int $order): JsonResponse
    {
        $model = $this->find($request, $order);

        if ($model->status !== OrderStatus::PendingPayment) {
            return response()->json([
                'message' => $model->status === OrderStatus::Cancelled
                    ? 'This order was cancelled.'
                    : 'This order is already paid for.',
            ], 422);
        }

        return response()->json(['data' => $this->payments->start($model)]);
    }

    /**
     * Confirm a payment
     *
     * Send back exactly what the SDK handed you. The signature is checked
     * against a secret the app does not hold, and the provider's own record is
     * then read — a signature proves the message is authentic, not that money
     * moved.
     *
     * If the app dies before reaching here, the webhook still completes the
     * order. This call only makes the customer's screen react sooner.
     */
    public function confirm(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'razorpay_payment_id' => ['required', 'string', 'max:100'],
            'razorpay_signature' => ['required', 'string', 'max:255'],
        ]);

        $model = $this->payments->confirmFromClient(
            $this->find($request, $order),
            $data['razorpay_payment_id'],
            $data['razorpay_signature'],
        );

        return response()->json([
            'message' => 'Payment received. Your order is with the restaurant.',
            'data' => new OrderResource($model->load('items')),
        ]);
    }

    protected function find(Request $request, int $order): Order
    {
        return $request->user()->orders()->with('payments')->findOrFail($order);
    }
}
