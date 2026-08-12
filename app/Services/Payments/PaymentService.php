<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\LiveState\OrderStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Money in and money back (EP6, EP7).
 *
 * Two rules run through all of it.
 *
 * **An unpaid order never reaches a kitchen.** Online orders are created at
 * `pending_payment`, which the merchant queue already filters out, and only
 * move to `placed` once the provider says the money is captured.
 *
 * **The client is never the authority.** An app reporting success is a hint;
 * a signature, or better a webhook, is evidence. Both paths converge on the
 * same method so they cannot disagree.
 */
class PaymentService
{
    public function __construct(
        protected PaymentGateway $gateway,
        protected OrderStateService $liveState,
    ) {}

    public static function onlineEnabled(): bool
    {
        return config('payments.gateway') !== null;
    }

    /**
     * Methods a customer may actually pick right now.
     *
     * Without a gateway configured this is cash only — offering a method that
     * cannot complete loses the basket at the final step.
     *
     * @return list<string>
     */
    public static function availableMethods(): array
    {
        $methods = config('payments.methods', [PaymentMethod::Cod->value]);

        if (! self::onlineEnabled()) {
            return array_values(array_intersect($methods, [PaymentMethod::Cod->value]));
        }

        return array_values($methods);
    }

    /**
     * Open a payment for an order that is waiting on one.
     *
     * @return array<string, mixed>
     */
    public function start(Order $order): array
    {
        $session = $this->gateway->createOrder($order);

        $payment = $order->payments()->where('status', PaymentStatus::Pending)->latest('id')->first();

        $payment?->update([
            'gateway' => config('payments.gateway'),
            'gateway_order_id' => $session['gateway_order_id'],
        ]);

        return [
            ...$session,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            // Prefilled in the SDK sheet so a customer is not typing their own
            // number while a payment timer runs.
            'prefill' => [
                'name' => $order->delivery_contact_name ?? $order->customer?->name,
                'contact' => $order->delivery_contact_phone ?? $order->customer?->phone,
                'email' => $order->customer?->email,
            ],
        ];
    }

    /**
     * The app came back from the SDK saying it worked.
     *
     * Trusted only as far as the signature, which is computed with a secret
     * the app does not hold.
     *
     * @throws ValidationException
     */
    public function confirmFromClient(Order $order, string $paymentId, string $signature): Order
    {
        $payment = $order->payments()->latest('id')->firstOrFail();

        if (! $this->gateway->verifySignature((string) $payment->gateway_order_id, $paymentId, $signature)) {
            $payment->update([
                'status' => PaymentStatus::Failed,
                'failure_reason' => 'Signature did not match.',
            ]);

            throw ValidationException::withMessages([
                'signature' => 'We could not verify that payment. Nothing has been charged — please try again.',
            ]);
        }

        /*
         * Even with a good signature, the provider's own record is what
         * decides. A signature proves the message is authentic; it does not
         * prove the money moved.
         */
        $remote = $this->gateway->fetchPayment($paymentId);

        if (! in_array($remote['status'], ['captured', 'authorized'], true)) {
            $payment->update([
                'status' => PaymentStatus::Failed,
                'gateway_payment_id' => $paymentId,
                'failure_reason' => 'Payment was not completed ('.$remote['status'].').',
            ]);

            throw ValidationException::withMessages([
                'payment' => 'That payment did not go through. Nothing has been charged.',
            ]);
        }

        return $this->markPaid($order, $paymentId, $signature, $remote['method'] ?? null);
    }

    /**
     * The provider told us directly.
     *
     * The authority, because it arrives whether or not the customer's app
     * survived the redirect — and it retries until we answer, which is why
     * every write here is idempotent.
     */
    public function confirmFromWebhook(Order $order, string $paymentId, ?string $method = null): Order
    {
        return $this->markPaid($order, $paymentId, null, $method);
    }

    /**
     * Send the money back.
     *
     * Called when a paid order is cancelled or rejected by anyone. COD orders
     * have nothing to refund and fall straight through.
     */
    public function refund(Order $order, string $reason, ?int $actorId = null): void
    {
        $payment = $order->payments()
            ->where('status', PaymentStatus::Paid)
            ->whereNotNull('gateway_payment_id')
            ->latest('id')
            ->first();

        if ($payment === null) {
            return;
        }

        // Keyed on the payment, so a second cancel or a retried job cannot
        // refund the same money twice.
        $key = 'refund-'.$payment->id;

        if ($order->refunds()->where('idempotency_key', $key)->exists()) {
            return;
        }

        $refund = $order->refunds()->create([
            'payment_id' => $payment->id,
            'amount' => $payment->amount,
            'status' => 'processing',
            'reason' => $reason,
            'initiated_by_user_id' => $actorId,
            'idempotency_key' => $key,
        ]);

        try {
            $result = $this->gateway->refund(
                (string) $payment->gateway_payment_id,
                (int) round((float) $payment->amount * 100),
                $key,
            );

            $refund->update([
                'gateway_refund_id' => $result['refund_id'],
                'status' => $result['status'] === 'processed' ? 'completed' : 'processing',
                'processed_at' => now(),
            ]);

            $payment->update(['status' => PaymentStatus::Refunded]);
        } catch (\Throwable $e) {
            /*
             * Left as `pending` with the reason recorded rather than thrown.
             * The order is already cancelled and the customer already told —
             * failing here would roll that back and leave them with neither
             * their food nor an explanation. A human settles the money.
             */
            $refund->update(['status' => 'failed', 'reason' => $reason.' — '.$e->getMessage()]);

            report($e);
        }
    }

    /**
     * The single place an order becomes paid, whichever route got us here.
     *
     * Idempotent: a webhook that arrives after the client already confirmed
     * finds the work done and changes nothing.
     */
    protected function markPaid(Order $order, string $paymentId, ?string $signature, ?string $method): Order
    {
        $payment = $order->payments()->latest('id')->firstOrFail();

        if ($payment->status === PaymentStatus::Paid) {
            return $order;
        }

        DB::transaction(function () use ($order, $payment, $paymentId, $signature, $method) {
            $payment->update([
                'status' => PaymentStatus::Paid,
                'gateway_payment_id' => $paymentId,
                'gateway_signature' => $signature,
                'method' => PaymentMethod::tryFrom((string) $method) ?? $payment->method,
                'idempotency_key' => $payment->idempotency_key ?? 'pay-'.$paymentId,
                'captured_at' => now(),
            ]);

            // Only now does the kitchen see it.
            if ($order->status === OrderStatus::PendingPayment) {
                $order->update(['status' => OrderStatus::Placed, 'placed_at' => now()]);

                $order->statusHistory()->create([
                    'from_status' => OrderStatus::PendingPayment,
                    'to_status' => OrderStatus::Placed,
                    'changed_by_user_id' => $order->user_id,
                    'note' => 'Payment received.',
                    'created_at' => now(),
                ]);
            }
        });

        rescue(fn () => $this->liveState->setStatus($order->id, OrderStatus::Placed), report: true);

        return $order->refresh();
    }

    /**
     * A key unique to this attempt, so a retried request cannot open two
     * payments for one order.
     */
    public static function attemptKey(Order $order): string
    {
        return 'order-'.$order->id.'-'.Str::random(8);
    }
}
