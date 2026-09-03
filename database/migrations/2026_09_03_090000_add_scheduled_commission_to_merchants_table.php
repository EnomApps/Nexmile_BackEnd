<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A commission rate that changes on a date the restaurant was told about.
 *
 * The launch offer is a lower rate for the first few months, and the only way
 * to end it today is an admin editing one merchant at a time and remembering
 * which date belonged to whom. With a hundred restaurants that does not
 * happen — either the offer never ends, or it ends for whoever the admin
 * happened to open that week.
 *
 * The failure that matters more is the other one: a rate that jumps with no
 * warning. A restaurant owner reconciling a payout against their own
 * arithmetic and finding an extra two percent gone is a restaurant owner who
 * leaves, and they are right to. Writing the change down in advance is what
 * makes it a term they agreed to rather than something that happened to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->decimal('scheduled_commission_rate', 5, 2)->nullable()->after('commission_rate');

            /*
             * A date, not a timestamp. "You move to 12% on 14 March" is what a
             * restaurant is told, and a rate that turned at 14:32 because that
             * is when the paperwork was filed would be indefensible when the
             * owner checks the day's payouts.
             */
            $table->date('commission_changes_on')->nullable()->after('scheduled_commission_rate');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['scheduled_commission_rate', 'commission_changes_on']);
        });
    }
};
