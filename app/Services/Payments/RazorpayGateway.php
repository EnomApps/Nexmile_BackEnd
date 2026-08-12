<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Razorpay (EP6).
 *
 * Plain HTTP rather than the vendor SDK: four endpoints, an HMAC, and no
 * desire to inherit someone else's exception hierarchy or dependency tree.
 * It also means Http::fake() covers the whole surface in tests.
 */
class RazorpayGateway implements PaymentGateway
{
    public function createOrder(Order $order): array
    {
        $amount = $this->toPaise((float) $order->grand_total);

        $response = $this->client()->post('/orders', [
            'amount' => $amount,
            'currency' => config('payments.currency'),
            /*
             * Our own order number, so a payment in the Razorpay dashboard can
             * be traced back without a lookup table. Their receipt field is
             * capped at 40 characters, which ours is comfortably under.
             */
            'receipt' => $order->order_number,
            'notes' => [
                'order_id' => (string) $order->id,
                'merchant' => (string) $order->merchant_id,
            ],
        ]);

        $this->guard($response, 'create a payment');

        return [
            'gateway_order_id' => $response->json('id'),
            'amount_paise' => $amount,
            'currency' => config('payments.currency'),
            // The publishable key. The secret never leaves the server.
            'key' => (string) config('payments.razorpay.key'),
        ];
    }

    public function verifySignature(string $gatewayOrderId, string $paymentId, string $signature): bool
    {
        $expected = hash_hmac(
            'sha256',
            $gatewayOrderId.'|'.$paymentId,
            (string) config('payments.razorpay.secret'),
        );

        // Constant time: a fast rejection tells an attacker how much of their
        // guess was right.
        return hash_equals($expected, $signature);
    }

    public function verifyWebhook(string $rawBody, string $signature): bool
    {
        $secret = (string) config('payments.razorpay.webhook_secret');

        /*
         * No secret means no way to tell a real webhook from a forged one, and
         * a forged one pushes an unpaid order into a kitchen. Refuse rather
         * than wave it through.
         */
        if ($secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    public function fetchPayment(string $paymentId): array
    {
        $response = $this->client()->get("/payments/{$paymentId}");

        $this->guard($response, 'read a payment');

        return [
            'status' => (string) $response->json('status'),
            'amount_paise' => (int) $response->json('amount'),
            'method' => $response->json('method'),
        ];
    }

    public function refund(string $paymentId, ?int $amountPaise = null, ?string $idempotencyKey = null): array
    {
        $payload = $amountPaise === null ? [] : ['amount' => $amountPaise];

        $client = $this->client();

        if ($idempotencyKey !== null) {
            // Razorpay honours this header, so a retried refund does not send
            // the customer their money twice.
            $client = $client->withHeaders(['X-Payment-Idempotency-Key' => $idempotencyKey]);
        }

        $response = $client->post("/payments/{$paymentId}/refund", $payload);

        $this->guard($response, 'refund a payment');

        return [
            'refund_id' => (string) $response->json('id'),
            'status' => (string) $response->json('status'),
        ];
    }

    /**
     * Rupees to paise.
     *
     * Rounded through a string because 430.10 * 100 is 43009.999… in binary
     * floating point, and truncating that bills the customer a paisa short
     * every time.
     */
    private function toPaise(float $rupees): int
    {
        return (int) round($rupees * 100);
    }

    private function client(): PendingRequest
    {
        $key = (string) config('payments.razorpay.key');
        $secret = (string) config('payments.razorpay.secret');

        if ($key === '' || $secret === '') {
            throw new RuntimeException('Razorpay keys are not configured.');
        }

        return Http::withBasicAuth($key, $secret)
            ->baseUrl((string) config('payments.razorpay.base_url'))
            ->acceptJson()
            ->timeout(15)
            /*
             * A customer is watching a spinner, so retry briefly and give up
             * rather than hold the request open. Anything genuinely lost is
             * recovered by the webhook.
             */
            ->retry(2, 200, throw: false);
    }

    private function guard(Response $response, string $what): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Razorpay could not %s: %s',
            $what,
            $response->json('error.description') ?? 'HTTP '.$response->status(),
        ));
    }
}
