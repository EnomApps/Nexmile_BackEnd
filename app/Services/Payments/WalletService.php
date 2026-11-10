<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTopup;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Every movement in or out of a customer's wallet.
 *
 * The only place `wallets.balance` is written. That is not tidiness — it is
 * the reason the locking can be trusted. A second writer somewhere else would
 * not take the same lock, and the bug would appear as a balance that is wrong
 * by one order, weeks later, with nothing in the logs.
 *
 * Two rules hold the whole thing up:
 *
 *   Lock before reading a balance you are about to change. Two phones
 *   checking out at once both read ₹200, both decide it covers ₹150, and the
 *   customer has eaten ₹300 of food for ₹200.
 *
 *   Give every credit an idempotency key. A gateway redelivers webhooks, a
 *   customer taps verify twice, a retry fires on a timeout that actually
 *   succeeded. Each of those is free money without one.
 *
 * Deliberately absent: any way to take money out other than by spending it.
 * A closed-loop wallet that cannot be cashed out sits outside the RBI's
 * prepaid instrument licensing regime; one that can does not. Adding a
 * withdraw method is a legal decision, not a feature.
 */
class WalletService
{
    /** The wallet for this customer, created on first sight. */
    public function for(User $user): Wallet
    {
        return Wallet::firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
    }

    public function balance(User $user): float
    {
        return (float) $this->for($user)->balance;
    }

    /**
     * Open a top-up at the gateway.
     *
     * The row is written before the gateway is called, so a response that gets
     * lost in transit still has something on our side to reconcile against.
     * A top-up nobody pays simply stays `created` and costs nothing.
     */
    public function openTopup(User $user, float $amount, array $gatewayOrder): WalletTopup
    {
        return WalletTopup::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'currency' => $gatewayOrder['currency'] ?? config('payments.currency'),
            'status' => WalletTopup::CREATED,
            'gateway' => config('payments.gateway'),
            'gateway_order_id' => $gatewayOrder['gateway_order_id'],
        ]);
    }

    /**
     * Credit a paid top-up, exactly once.
     *
     * Both the app's verify call and Razorpay's webhook land here, and either
     * may arrive first. Whichever does credits; the other finds `credited_at`
     * set inside the lock and returns the wallet untouched.
     *
     * The lock is on the top-up rather than the wallet because the top-up is
     * what must not happen twice. The wallet is locked underneath it by
     * `move()`, in that order everywhere, so two credits to one wallet cannot
     * deadlock against each other.
     */
    public function creditTopup(WalletTopup $topup, ?string $paymentId = null, array $payload = []): Wallet
    {
        return DB::transaction(function () use ($topup, $paymentId, $payload) {
            $locked = WalletTopup::whereKey($topup->id)->lockForUpdate()->firstOrFail();

            if ($locked->isCredited()) {
                // Already done by whichever of verify and webhook got here
                // first. Not an error, and not worth a log line: it is the
                // normal outcome for the slower of the two.
                return $this->for($locked->user);
            }

            $locked->forceFill([
                'status' => WalletTopup::PAID,
                'gateway_payment_id' => $paymentId ?? $locked->gateway_payment_id,
                'gateway_payload' => $payload ?: $locked->gateway_payload,
                'credited_at' => now(),
            ])->save();

            return $this->move(
                $locked->user,
                (float) $locked->amount,
                WalletTransaction::CREDIT,
                WalletTransaction::TOPUP,
                [
                    'wallet_topup_id' => $locked->id,
                    'description' => 'Money added',
                    // Ties the credit to the one top-up it belongs to, so the
                    // unique index refuses a second even if the lock did not.
                    'idempotency_key' => 'topup:'.$locked->id,
                ],
            );
        });
    }

    /**
     * Spend from the wallet on an order.
     *
     * Called from inside the checkout transaction, so a failure anywhere later
     * in placing the order takes the debit with it. A customer charged for an
     * order that does not exist is the worst outcome available here.
     *
     * Returns what was actually taken, which is never more than the balance
     * and never more than the bill.
     */
    public function spend(User $user, Order $order, float $upTo): float
    {
        $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first()
            ?? $this->for($user);

        /*
         * Read after the lock, not before. The balance a request read while
         * deciding what to offer the customer is already history by the time
         * it gets here.
         */
        $taking = min((float) $wallet->balance, $upTo);

        if ($taking <= 0) {
            return 0.0;
        }

        $this->move(
            $user,
            $taking,
            WalletTransaction::DEBIT,
            WalletTransaction::ORDER_PAYMENT,
            [
                'order_id' => $order->id,
                'description' => 'Order '.$order->order_number,
                'idempotency_key' => 'order:'.$order->id,
            ],
            $wallet,
        );

        return $taking;
    }

    /**
     * Put back what an order took, when it is cancelled or refunded.
     *
     * Money that came out of the wallet goes back to the wallet. Money that
     * came from a card goes back to the card — refunding it to the balance
     * instead would be converting someone's money into credit without asking,
     * which is a complaint even when the amount is right.
     */
    public function refundOrder(Order $order, ?string $reason = null): ?WalletTransaction
    {
        $taken = (float) $order->wallet_amount;

        if ($taken <= 0) {
            return null;
        }

        return DB::transaction(function () use ($order, $taken, $reason) {
            $wallet = $this->move(
                $order->customer,
                $taken,
                WalletTransaction::CREDIT,
                WalletTransaction::REFUND,
                [
                    'order_id' => $order->id,
                    'description' => $reason ?? 'Refund for order '.$order->order_number,
                    // One refund per order. A cancel processed twice — by a
                    // customer and then by support — credits once.
                    'idempotency_key' => 'refund:'.$order->id,
                ],
            );

            return $wallet->transactions()->latest('id')->first();
        });
    }

    /**
     * The single place a balance changes.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function move(
        User $user,
        float $amount,
        string $direction,
        string $type,
        array $attributes = [],
        ?Wallet $locked = null,
    ): Wallet {
        return DB::transaction(function () use ($user, $amount, $direction, $type, $attributes, $locked) {
            $wallet = $locked ?? Wallet::where('user_id', $user->id)->lockForUpdate()->first()
                ?? $this->for($user);

            /*
             * An already-recorded movement is already in the balance. Checked
             * inside the lock so two callers cannot both find nothing and both
             * write; the unique index would catch the second, but as an error
             * on a customer's screen rather than a no-op.
             */
            $key = $attributes['idempotency_key'] ?? null;

            if ($key !== null && WalletTransaction::where('idempotency_key', $key)->exists()) {
                return $wallet->refresh();
            }

            $after = $direction === WalletTransaction::CREDIT
                ? (float) $wallet->balance + $amount
                : (float) $wallet->balance - $amount;

            /*
             * A wallet must never go negative. Reaching here with an overdraw
             * means a caller skipped the lock or did its own arithmetic, and
             * the right response is to lose the transaction rather than the
             * money — this rolls the whole thing back, checkout included.
             */
            if ($after < 0) {
                throw new RuntimeException(
                    "Wallet {$wallet->id} would go negative: balance {$wallet->balance}, {$direction} {$amount}."
                );
            }

            $wallet->transactions()->create([
                'type' => $type,
                'direction' => $direction,
                'amount' => round($amount, 2),
                'balance_after' => round($after, 2),
                ...$attributes,
            ]);

            $wallet->forceFill(['balance' => round($after, 2)])->save();

            return $wallet;
        });
    }
}
