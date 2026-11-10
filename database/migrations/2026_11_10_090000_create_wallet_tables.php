<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer wallet.
 *
 * A closed-loop balance: money goes in by card or UPI and comes out only as
 * food. **It must never be withdrawable to a bank account.** That single rule
 * is what keeps this outside the RBI's prepaid payment instrument licensing
 * regime — a wallet somebody can cash out is a different financial product
 * with a different legal answer, and adding a withdraw button is not a feature
 * decision.
 *
 * Three tables rather than a balance column on users:
 *
 *   wallets              the balance, and the row that gets locked
 *   wallet_transactions  the ledger, which explains the balance
 *   wallet_topups        money coming in, and its life at the gateway
 *
 * The balance is stored rather than summed on read. Summing cannot drift, but
 * it also cannot be locked cheaply, and every spend needs a lock to stop two
 * phones spending the same hundred rupees. The ledger is the check on it:
 * nexmile:check-wallets re-adds it and reports any wallet that disagrees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            /*
             * Never negative. The database says so as well as the code,
             * because an overdrawn wallet is money given away and the bug that
             * produced it would be long gone by the time anybody noticed.
             */
            $table->decimal('balance', 10, 2)->default(0);

            $table->timestamps();
        });

        Schema::create('wallet_topups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('INR');

            /*
             * created — opened at the gateway, nobody has paid
             * paid     — money captured and the wallet credited, exactly once
             * failed   — the gateway told us it will not happen
             */
            $table->enum('status', ['created', 'paid', 'failed'])->default('created')->index();

            $table->string('gateway', 30)->nullable();
            $table->string('gateway_order_id')->nullable()->index();
            $table->string('gateway_payment_id')->nullable()->index();
            $table->string('gateway_signature')->nullable();
            $table->json('gateway_payload')->nullable();
            $table->string('failure_reason')->nullable();

            /*
             * Set in the same locked transaction as the credit. Its presence
             * is what makes a second verify, or a redelivered webhook, a
             * no-op rather than free money.
             */
            $table->dateTime('credited_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();

            $table->enum('type', ['topup', 'order_payment', 'refund', 'adjustment']);
            $table->enum('direction', ['credit', 'debit']);
            $table->decimal('amount', 10, 2);

            /*
             * The balance this row produced.
             *
             * Redundant, and worth it. A customer asking "where did my ₹200
             * go" is answered by reading down a column rather than by adding
             * up the whole history and hoping, and a ledger that disagrees
             * with itself is visible at a glance.
             */
            $table->decimal('balance_after', 10, 2);

            // What it was for. Both nullable: an adjustment has neither.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('wallet_topup_id')->nullable()->constrained()->nullOnDelete();

            $table->string('description')->nullable();

            /*
             * The second line of defence behind the row lock. A credit names
             * the thing it is paying for, so a duplicate insert is refused by
             * the index even if the lock were somehow bypassed.
             */
            $table->string('idempotency_key', 80)->nullable()->unique();

            $table->timestamps();

            // The statement query: one wallet, newest first.
            $table->index(['wallet_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallet_topups');
        Schema::dropIfExists('wallets');
    }
};
