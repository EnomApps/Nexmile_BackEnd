<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Long-lived refresh tokens paired with short-lived Sanctum access tokens.
 *
 * Access tokens expire in an hour so a stolen one has a short useful life;
 * the refresh token lets the app stay signed in for weeks without the user
 * re-entering an OTP.
 *
 * Tokens are stored hashed for the same reason as OTP codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('token_hash', 64)->unique();

            // Links this refresh token to the access token issued with it, so
            // revoking one revokes both.
            $table->unsignedBigInteger('access_token_id')->nullable()->index();

            $table->string('device_name')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            /*
             * Rotation: using a refresh token issues a new one and points the
             * old at its replacement. If a token that was already rotated is
             * presented again, it has been stolen — the whole chain is revoked.
             */
            $table->foreignId('replaced_by_id')->nullable()
                ->constrained('refresh_tokens')->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
