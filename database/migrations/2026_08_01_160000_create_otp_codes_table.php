<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time login codes (EP2).
 *
 * Codes are stored hashed. A leaked database dump must not let anyone log in
 * as any user by reading pending codes, so the plaintext exists only in the
 * SMS and in memory for the moment it is generated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 15)->index();
            $table->string('code_hash');
            $table->enum('purpose', ['login', 'phone_verification'])->default('login');

            // Which role to create the account as, when the phone is new.
            $table->enum('intended_role', ['customer', 'rider'])->default('customer');

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            // Verification looks up the newest unconsumed code for a phone.
            $table->index(['phone', 'consumed_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
