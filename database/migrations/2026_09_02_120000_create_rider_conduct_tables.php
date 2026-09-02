<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting a rider, and the record of what was done about it.
 *
 * A customer had no way to say a rider was rude, asked for extra money or
 * turned up with the food spilled — and an admin had no way to see a pattern
 * even if they had been told. The only tool was suspension, which is far too
 * blunt to be a first response and so never gets used until it is far too
 * late.
 *
 * Two tables on purpose. A report is what a customer said; a warning is what
 * Nexmile decided. Collapsing them would mean a rider is punished by whoever
 * complains loudest, and a customer angling for a refund is the most motivated
 * complainant there is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_reports', function (Blueprint $table) {
            $table->id();

            /*
             * Tied to an order, not to a rider directly. The order is what
             * proves the two people met, and it is what an admin needs to see
             * to judge the story — the time, the address, how long it took.
             */
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reported_by_user_id')->constrained('users')->cascadeOnDelete();

            $table->string('category', 40);
            $table->text('description')->nullable();

            // pending until a person looks at it. Never acted on automatically.
            $table->string('status', 20)->default('pending');

            $table->foreignId('reviewed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->timestamps();

            // One report per order per customer. Repeating it does not make it
            // more true, and it would let one person manufacture a pattern.
            $table->unique(['order_id', 'reported_by_user_id']);

            // The queue an admin works through, and the history on a rider.
            $table->index(['status', 'created_at']);
            $table->index(['rider_id', 'status']);
        });

        Schema::create('rider_warnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rider_id')->constrained()->cascadeOnDelete();

            /*
             * Usually raised from a report, but not always — a merchant
             * complaint or something an admin witnessed is still a warning,
             * and forcing a fake report to record it would corrupt the thing
             * the reports table is for.
             */
            $table->foreignId('rider_report_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('reason');
            $table->foreignId('issued_by_user_id')->constrained('users')->cascadeOnDelete();

            $table->timestamps();

            $table->index(['rider_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_warnings');
        Schema::dropIfExists('rider_reports');
    }
};
