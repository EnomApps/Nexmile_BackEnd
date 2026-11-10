<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How an order was paid for, when it was paid for two ways.
 *
 * A wallet rarely covers a basket exactly. The usual case is a partial: ₹120
 * from the balance and the rest in cash at the door. Both halves are stored
 * rather than one derived from the other, because `cod_amount` is the number a
 * rider reads off their screen and asks a customer for — and a figure computed
 * from a total that a later refund has touched is a rider arguing with a
 * customer on a doorstep about who is right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('wallet_amount', 10, 2)->default(0)->after('grand_total');

            // What the rider collects. Zero on a fully prepaid order.
            $table->decimal('cod_amount', 10, 2)->default(0)->after('wallet_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['wallet_amount', 'cod_amount']);
        });
    }
};
