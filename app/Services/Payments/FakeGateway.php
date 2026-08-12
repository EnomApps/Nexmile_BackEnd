<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use Illuminate\Support\Str;

/**
 * A gateway for local work and tests.
 *
 * Every signature it issues is real HMAC over a known secret, so the
 * verification path is genuinely exercised rather than stubbed out — the same
 * reasoning as generating a real PDF for the KYC upload tests. A fake that
 * always answers "yes" would let a broken signature check pass.
 */
class FakeGateway implements PaymentGateway
{
    public const SECRET = 'fake-gateway-secret';

    public function createOrder(Order $order): array
    {
        return [
            'gateway_order_id' => 'order_'.Str::upper(Str::random(14)),
            'amount_paise' => (int) round((float) $order->grand_total * 100),
            'currency' => config('payments.currency'),
            'key' => 'rzp_test_fake',
        ];
    }

    public function verifySignature(string $gatewayOrderId, string $paymentId, string $signature): bool
    {
        return hash_equals($this->sign($gatewayOrderId.'|'.$paymentId), $signature);
    }

    public function verifyWebhook(string $rawBody, string $signature): bool
    {
        return hash_equals($this->sign($rawBody), $signature);
    }

    public function fetchPayment(string $paymentId): array
    {
        return [
            // Anything prefixed pay_fail_ reports a failure, so the unhappy
            // path is reachable without a real bank declining a card.
            'status' => str_starts_with($paymentId, 'pay_fail_') ? 'failed' : 'captured',
            'amount_paise' => 0,
            'method' => 'upi',
        ];
    }

    public function refund(string $paymentId, ?int $amountPaise = null, ?string $idempotencyKey = null): array
    {
        return [
            'refund_id' => 'rfnd_'.Str::upper(Str::random(14)),
            'status' => 'processed',
        ];
    }

    /** What a test uses to produce a signature the verifier will accept. */
    public function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, self::SECRET);
    }
}
