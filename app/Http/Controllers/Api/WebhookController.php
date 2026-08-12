<?php

namespace App\Http\Controllers\Api;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Razorpay talking to us directly (EP6).
 *
 * This is the authority on whether money moved, not the customer's app. It
 * arrives whether or not their phone survived the redirect, whether or not
 * they force-closed the app on the bank's page — which is exactly when a
 * client callback does not.
 *
 * Unauthenticated by necessity, so the signature is the only thing standing
 * between this URL and a stranger pushing unpaid orders into kitchens.
 */
class WebhookController extends Controller
{
    public function __construct(
        protected PaymentGateway $gateway,
        protected PaymentService $payments,
    ) {}

    public function razorpay(Request $request): JsonResponse
    {
        /*
         * Verified against the raw body. Re-encoding a decoded payload changes
         * bytes — key order, unicode escaping, float formatting — and breaks
         * the comparison for reasons nobody enjoys debugging at midnight.
         */
        $raw = $request->getContent();
        $signature = (string) $request->header('X-Razorpay-Signature', '');

        if (! $this->gateway->verifyWebhook($raw, $signature)) {
            Log::warning('Rejected a Razorpay webhook with a bad signature.', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = (string) $request->input('event');
        $payment = $request->input('payload.payment.entity', []);

        $orderId = (int) ($payment['notes']['order_id'] ?? 0);
        $order = $orderId > 0 ? Order::with('payments')->find($orderId) : null;

        if ($order === null) {
            // Acknowledged, not retried. An unknown order is not something a
            // redelivery will fix, and refusing makes Razorpay hammer us.
            Log::warning('Razorpay webhook for an unknown order.', ['event' => $event, 'order_id' => $orderId]);

            return response()->json(['message' => 'Ignored.']);
        }

        match ($event) {
            'payment.captured' => $this->payments->confirmFromWebhook(
                $order,
                (string) ($payment['id'] ?? ''),
                $payment['method'] ?? null,
            ),
            'payment.failed' => $this->recordFailure($order, $payment),
            // Everything else is noise we do not act on: refunds we initiated,
            // settlement events, order.paid duplicating payment.captured.
            default => null,
        };

        /*
         * Always 200 once the signature is good. Razorpay retries on anything
         * else, and a bug in our handling would turn into a storm of
         * redeliveries for an order we already dealt with.
         */
        return response()->json(['message' => 'Handled.']);
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    protected function recordFailure(Order $order, array $payment): void
    {
        $latest = $order->payments()->latest('id')->first();

        // Never overwrite a success: a failed attempt can arrive after the
        // customer retried and paid.
        if ($latest === null || $latest->status === PaymentStatus::Paid) {
            return;
        }

        $latest->update([
            'status' => PaymentStatus::Failed,
            'gateway_payment_id' => $payment['id'] ?? null,
            'failure_reason' => $payment['error_description'] ?? 'Payment failed.',
        ]);
    }
}
