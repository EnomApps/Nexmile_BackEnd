<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rider invites someone they know, and earns when that person starts working.
 *
 * Two tables, and the split is the same one the conduct system makes. An
 * invitation is a claim about a phone number that may never come to anything.
 * A bonus is money that has been earned and must never be earned twice.
 * Keeping them in one row would mean the record of who invited whom carries a
 * running balance, and every recount is a chance to pay again.
 *
 * Almost nothing about progress is stored here. Whether the friend joined,
 * passed KYC or has started delivering is read off their own rider record,
 * which is the only copy that cannot drift. The one thing worth writing down
 * is the money, because that is the part a recount must not change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_referrals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('referrer_rider_id')->constrained('riders')->cascadeOnDelete();

            /*
             * The invitation is made before the person exists, so a phone
             * number is all there is to hold on to. Stored as ten digits with
             * nothing else — a rider typing +91 98765 43210 and a friend
             * signing up as 9876543210 are the same person, and an invite that
             * silently never matches is worse than one refused outright.
             */
            $table->string('referred_phone', 10)->index();

            // What the referrer typed, so their own list is readable to them.
            $table->string('referred_name')->nullable();
            $table->string('referred_city', 80)->nullable();

            /*
             * Filled when someone signs up on that number. Unique: a rider can
             * be referred once, by one person, for ever. Two people claiming
             * the same recruit is a dispute nobody can settle afterwards, so
             * the first invitation wins and the column enforces it.
             */
            $table->foreignId('referred_rider_id')->nullable()->unique()
                ->constrained('riders')->nullOnDelete();

            $table->dateTime('invited_at');

            /*
             * An invite nobody took up stops holding the number. Otherwise a
             * friend who joins nine months later earns nobody anything,
             * because January's invitation is still sitting on them.
             */
            $table->dateTime('expires_at');

            $table->timestamps();

            // One rider cannot invite the same number twice.
            $table->unique(['referrer_rider_id', 'referred_phone']);

            // The referrer's own list, newest first.
            $table->index(['referrer_rider_id', 'created_at']);
        });

        Schema::create('rider_referral_bonuses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rider_referral_id')->constrained()->cascadeOnDelete();

            /*
             * The referrer again, denormalised. "What have I earned from
             * referrals" is the question this table exists to answer and it
             * should not need a join to a second table to do it.
             */
            $table->foreignId('rider_id')->constrained('riders')->cascadeOnDelete();

            /*
             * The threshold as it stood when this was earned, not as config
             * has it today. Changing the scheme must never restate what
             * somebody was already paid, and a rider who cannot check last
             * month's bonus against last month's rules has no reason to
             * believe this month's.
             */
            $table->unsignedInteger('deliveries_required');
            $table->decimal('amount', 10, 2);

            $table->dateTime('earned_at');
            $table->timestamps();

            /*
             * The line that stops a bonus being paid twice. Every other guard
             * is a check that could be raced by two deliveries landing at
             * once; this one is the database refusing.
             */
            // Named, because the generated name is over MySQL's 64 characters.
            $table->unique(['rider_referral_id', 'deliveries_required'], 'referral_milestone_unique');

            $table->index(['rider_id', 'earned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_referral_bonuses');
        Schema::dropIfExists('rider_referrals');
    }
};
