<?php

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment module (EP6).
 *
 * Payments are a separate table rather than columns on orders because a single
 * order can have several attempts — a failed UPI collect followed by a
 * successful one, or a part-wallet, part-UPI split.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->enum('method', PaymentMethod::values());
            $table->enum('status', PaymentStatus::values())
                ->default(PaymentStatus::Pending->value)->index();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('INR');

            $table->string('gateway', 30)->nullable();          // razorpay, null for COD/wallet
            $table->string('gateway_order_id')->nullable();
            $table->string('gateway_payment_id')->nullable();
            $table->string('gateway_signature')->nullable();
            $table->string('failure_reason')->nullable();

            /*
             * Gateways retry webhooks, and a duplicate delivery must not credit
             * an order twice. Writes are keyed on this.
             */
            $table->string('idempotency_key', 64)->nullable()->unique();

            $table->json('gateway_payload')->nullable();
            $table->timestamp('authorised_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('gateway_payment_id');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();

            $table->decimal('amount', 10, 2);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])
                ->default('pending')->index();
            $table->string('reason')->nullable();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('gateway_refund_id')->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->json('gateway_payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
    }
};
