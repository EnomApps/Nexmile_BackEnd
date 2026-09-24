<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a rider is actually paid for a week's work.
 *
 * Earnings have been recorded per order since dispatch was built and referral
 * bonuses since riders started recruiting each other, but nothing ever added
 * them up and sent money. A rider reading a balance they have no way to
 * receive is worse off than one shown nothing at all.
 *
 * Every figure is written once and never recomputed. A payout restated later
 * from current rates would quietly change what somebody was already paid, and
 * a rider who cannot check last week's statement against last week's numbers
 * has no reason to believe this week's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rider_id')->constrained()->cascadeOnDelete();

            // The week being paid for. Dates, because a rider is told "7 to 13
            // September" and a boundary that moved with the clock would put an
            // order in a week they were not paid for.
            $table->date('period_start');
            $table->date('period_end');

            /*
             * The parts, kept separate because they answer different
             * questions. "Why is this less than I counted" has one answer and
             * it should be on the same screen as the number that prompts it.
             */
            $table->decimal('delivery_earnings', 10, 2)->default(0);
            $table->decimal('referral_earnings', 10, 2)->default(0);

            /*
             * Weeks too small to send, folded into this one.
             *
             * Recorded rather than recalculated. The carried rows are marked
             * as they are consumed, so a second run of the same week finds
             * nothing left to carry and would quietly rebuild the total
             * without it — leaving the rider short by exactly the amount that
             * was too small to notice.
             */
            $table->decimal('carried_in', 10, 2)->default(0);

            $table->decimal('gross', 10, 2)->default(0);

            $table->decimal('tds', 10, 2)->default(0);

            /*
             * The rate and the running total as they stood when this was
             * calculated. Changing the scheme next quarter must never restate
             * a deduction already made, and a rider querying an old statement
             * is owed the arithmetic rather than today's answer again.
             */
            $table->decimal('tds_rate', 5, 2)->nullable();
            $table->decimal('tds_year_to_date', 12, 2)->nullable();

            $table->decimal('net', 10, 2)->default(0);

            /*
             * pending  — calculated, not yet sent
             * paid     — money left the bank
             * carried  — under the minimum, rolled into the next week
             * failed   — the transfer was attempted and did not land
             */
            $table->string('status', 20)->default('pending')->index();

            $table->timestamp('paid_at')->nullable();

            // Whatever the bank or payout provider called it. The first thing
            // anybody asks for when a rider says the money never arrived.
            $table->string('reference')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            /*
             * One payout per rider per week. The run is going to be re-run —
             * by a cron that fired twice, by somebody checking it worked — and
             * paying a week twice is the one mistake that cannot be undone by
             * editing a row.
             */
            $table->unique(['rider_id', 'period_start']);

            $table->index(['period_start', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_payouts');
    }
};
