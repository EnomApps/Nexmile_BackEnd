<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WalletTopup;
use App\Models\WalletTransaction;
use App\Services\Payments\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The customer's wallet: balance, statement and topping up.
 *
 * Money only ever leaves this wallet as food. There is no withdrawal endpoint
 * and there should not be one — a closed-loop balance that cannot be cashed
 * out sits outside the RBI's prepaid instrument licensing regime, and a
 * balance that can does not.
 */
class WalletController extends Controller
{
    public function __construct(
        protected WalletService $wallets,
        protected PaymentGateway $gateway,
    ) {}

    /**
     * Balance and recent transactions
     *
     * Newest first. The statement is what answers "where did my ₹200 go"
     * without anybody having to ask support.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->wallets->for($request->user()))]);
    }

    /**
     * Start a top-up
     *
     * Opens a payment on the provider and hands back what the app needs for
     * its SDK. Nothing is credited here — money has not moved yet, and the
     * app saying it did is not evidence.
     */
    public function topup(Request $request): JsonResponse
    {
        $minimum = (float) config('payments.wallet.minimum_topup');
        $maximum = (float) config('payments.wallet.maximum_topup');

        $data = $request->validate([
            'amount' => ['required', 'numeric', "min:{$minimum}", "max:{$maximum}"],
        ], [
            'amount.min' => "The smallest top-up is ₹{$minimum}.",
            'amount.max' => "The largest top-up is ₹{$maximum}.",
        ]);

        if (! config('payments.wallet.enabled') || config('payments.gateway') === null) {
            /*
             * No gateway means no way to take the money. Refused here rather
             * than at the provider, where it would surface as an unexplained
             * failure after the customer had already chosen an amount.
             */
            return response()->json(['message' => 'Adding money is unavailable right now.'], 503);
        }

        $user = $request->user();
        $amount = round((float) $data['amount'], 2);
        $wallet = $this->wallets->for($user);

        $ceiling = (float) config('payments.wallet.maximum_balance');

        if ((float) $wallet->balance + $amount > $ceiling) {
            $room = max(0, round($ceiling - (float) $wallet->balance, 2));

            return response()->json([
                'message' => $room > 0
                    ? "Your wallet can hold ₹{$ceiling}. You can add up to ₹{$room} more."
                    : "Your wallet is at its ₹{$ceiling} limit. Spend some before adding more.",
                'errors' => ['amount' => ['This would take your balance over the limit.']],
            ], 422);
        }

        /*
         * Opened at the provider first, then recorded. A top-up row with no
         * gateway order behind it would be a payment nobody can ever complete
         * and support cannot explain.
         */
        try {
            $payment = $this->gateway->createPayment(
                (int) round($amount * 100),
                'topup-'.$user->id.'-'.now()->format('YmdHis'),
                ['purpose' => 'wallet_topup', 'user_id' => (string) $user->id],
            );
        } catch (\Throwable $e) {
            Log::error('Could not open a wallet top-up.', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Could not start the payment. Try again.'], 502);
        }

        /*
         * The webhook finds this row by its gateway order id rather than by
         * a note carrying our own id. The note would have to be written
         * before the row exists to be sent with the payment, and a webhook
         * that beat us to it would have nothing to match against.
         */
        $topup = $this->wallets->openTopup($user, $amount, $payment);

        return response()->json([
            'data' => [
                'id' => $topup->id,
                'razorpay_order_id' => $payment['gateway_order_id'],
                // The publishable key, fetched rather than hardcoded in the
                // app, so rotating it does not need a release.
                'razorpay_key_id' => $payment['key'],
                'amount_paise' => $payment['amount_paise'],
                'currency' => $payment['currency'],
            ],
        ], 201);
    }

    /**
     * Confirm a top-up
     *
     * The signature is the evidence, not the app's word for it. Safe to call
     * twice: the credit happens once, and a repeat returns the same wallet.
     */
    public function verify(Request $request, int $topup): JsonResponse
    {
        $data = $request->validate([
            'razorpay_payment_id' => ['required', 'string', 'max:120'],
            'razorpay_order_id' => ['required', 'string', 'max:120'],
            'razorpay_signature' => ['required', 'string', 'max:255'],
        ]);

        // Scoped to the caller: another customer's top-up id is a 404, not a
        // way to credit somebody else's balance or read it.
        $model = WalletTopup::where('user_id', $request->user()->id)->findOrFail($topup);

        /*
         * The order id has to be the one we opened. Without this check a
         * customer could present a genuine signature from any payment of
         * their own — a ₹10 top-up's signature against a ₹5,000 row.
         */
        if (! hash_equals((string) $model->gateway_order_id, $data['razorpay_order_id'])) {
            Log::warning('Wallet top-up verified against the wrong gateway order.', [
                'topup_id' => $model->id,
                'user_id' => $request->user()->id,
            ]);

            return response()->json(['message' => 'That payment does not belong to this top-up.'], 422);
        }

        $good = $this->gateway->verifySignature(
            $data['razorpay_order_id'],
            $data['razorpay_payment_id'],
            $data['razorpay_signature'],
        );

        if (! $good) {
            Log::warning('Rejected a wallet top-up with a bad signature.', [
                'topup_id' => $model->id,
                'user_id' => $request->user()->id,
            ]);

            return response()->json(['message' => 'We could not verify that payment.'], 422);
        }

        $model->forceFill(['gateway_signature' => $data['razorpay_signature']])->save();

        $wallet = $this->wallets->creditTopup($model, $data['razorpay_payment_id']);

        return response()->json(['data' => $this->present($wallet)]);
    }

    /** @return array<string, mixed> */
    protected function present(Wallet $wallet): array
    {
        $rows = $wallet->transactions()
            ->with('order:id,order_number')
            ->latest('id')
            ->limit((int) config('payments.wallet.statement_size'))
            ->get();

        return [
            'balance' => (float) $wallet->balance,
            'currency' => config('payments.currency'),
            'transactions' => $rows->map(fn (WalletTransaction $row) => [
                'id' => $row->id,
                'type' => $row->type,
                'direction' => $row->direction,
                'amount' => (float) $row->amount,
                'balance_after' => (float) $row->balance_after,
                'description' => $row->description ?? '',
                'order_number' => $row->order?->order_number,
                'created_at' => $row->created_at,
            ])->all(),
        ];
    }
}
