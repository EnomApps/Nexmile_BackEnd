<?php

namespace App\Contracts;

use App\Models\Order;

/**
 * What a payment provider has to be able to do (EP6).
 *
 * Narrow on purpose. Swapping Razorpay for another aggregator should be one
 * new class, not a search for every place a gateway name is mentioned — the
 * same reasoning as the SMS driver.
 */
interface PaymentGateway
{
    /**
     * Open a payment on the provider and return what the app needs to hand to
     * its SDK.
     *
     * @return array{gateway_order_id: string, amount_paise: int, currency: string, key: string}
     */
    public function createOrder(Order $order): array;

    /**
     * Open a payment for a bare amount, with no order behind it.
     *
     * A wallet top-up is money arriving before anybody has decided what to
     * spend it on, so there is no order to pass and nothing in the orders
     * table to point at. `notes` is echoed back on the webhook, which is
     * how an incoming payment is matched to what it was for.
     *
     * @param  array<string, string>  $notes
     * @return array{gateway_order_id: string, amount_paise: int, currency: string, key: string}
     */
    public function createPayment(int $amountPaise, string $receipt, array $notes = []): array;

    /**
     * Whether the signature the app returned genuinely came from the provider.
     *
     * The app saying "it worked" is not evidence — a signature the provider
     * computed with a secret only the two of us hold is.
     */
    public function verifySignature(string $gatewayOrderId, string $paymentId, string $signature): bool;

    /**
     * Whether a webhook body genuinely came from the provider.
     *
     * Computed over the raw body, because re-encoding a decoded payload
     * changes bytes and breaks the comparison for reasons nobody enjoys
     * debugging at midnight.
     */
    public function verifyWebhook(string $rawBody, string $signature): bool;

    /**
     * The provider's own record of a payment, which outranks anything a client
     * told us.
     *
     * @return array{status: string, amount_paise: int, method: string|null}
     */
    public function fetchPayment(string $paymentId): array;

    /**
     * Send money back. Amount in paise; null refunds the lot.
     *
     * @return array{refund_id: string, status: string}
     */
    public function refund(string $paymentId, ?int $amountPaise = null, ?string $idempotencyKey = null): array;
}
