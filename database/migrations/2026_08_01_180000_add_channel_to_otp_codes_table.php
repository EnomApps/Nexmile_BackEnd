<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generalises OTP codes from phone-only to any identifier.
 *
 * Codes go by email until DLT registration and an SMS gateway are in place;
 * the same table then serves both channels without a second migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            // Indexes reference the column by name, so they have to go first.
            $table->dropIndex(['phone', 'consumed_at', 'expires_at']);
            $table->dropIndex(['phone']);
            $table->renameColumn('phone', 'identifier');
        });

        Schema::table('otp_codes', function (Blueprint $table) {
            // 15 characters fitted a mobile number; an email address needs more.
            $table->string('identifier', 191)->change();

            $table->enum('channel', ['sms', 'email'])->default('sms')->after('identifier');

            $table->index('identifier');
            $table->index(['identifier', 'consumed_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropIndex(['identifier', 'consumed_at', 'expires_at']);
            $table->dropIndex(['identifier']);
            $table->dropColumn('channel');
            $table->renameColumn('identifier', 'phone');
        });

        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('phone', 15)->change();
            $table->index('phone');
            $table->index(['phone', 'consumed_at', 'expires_at']);
        });
    }
};
