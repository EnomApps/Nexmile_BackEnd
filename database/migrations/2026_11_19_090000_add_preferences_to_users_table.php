<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two customer preferences that have to follow the person, not the handset.
 *
 * On the user row rather than in a preferences table: they are two booleans
 * read on nearly every app open, and a join for two booleans is a join for
 * nothing. A third and fourth would change that answer.
 *
 * Theme and app language are deliberately absent — those stay on the phone,
 * because a customer who picks dark mode on their tablet has said nothing
 * about their handset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Veg mode is a filter, not a diet declaration: it hides non-veg
            // restaurants and opens every menu on its veg dishes.
            $table->boolean('veg_mode')->default(false)->after('preferred_locale');

            /*
             * Opt-out, not opt-in. Defaulting this to false would silence
             * every offer for every customer who never finds the setting,
             * which is not consent, it is just an unread switch. Order
             * updates are not marketing and never consult it.
             */
            $table->boolean('promotional_notifications')->default(true)->after('veg_mode');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['veg_mode', 'promotional_notifications']);
        });
    }
};
