<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where to reach a person when their app is closed.
 *
 * A rider's phone is in their pocket with the screen off, and a suspended
 * app's socket is dead — only FCM can wake it. This is the table that makes
 * "an order is waiting" reach someone who is not looking at the screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * FCM tokens are long and have no fixed length. Indexed by a hash
             * rather than the column itself: MySQL cannot index a 512-char
             * string under the default row format, and the only lookup is an
             * exact match.
             */
            $table->string('token', 512);
            $table->char('token_hash', 64)->unique();

            $table->string('platform', 10);

            /*
             * One person may hold two roles — a rider ordering their dinner is
             * a customer. The same phone then wants rider alerts in one app and
             * customer alerts in the other, and they are different installs
             * with different tokens.
             */
            $table->string('app', 10);

            // For pruning: FCM rejects tokens that have gone stale, and an app
            // that has not checked in for months is an uninstall.
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'app']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
