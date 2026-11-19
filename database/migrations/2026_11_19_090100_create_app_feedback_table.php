<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Rate the app" from the profile page. Not a review of a restaurant, a rider
 * or an order — those already have their own tables and their own meaning.
 *
 * `nullOnDelete` rather than cascade. A customer closing their account should
 * take their name with them, not the sentence that told us the app was slow on
 * 2G; the row survives with nobody attached to it. That is also what makes it
 * safe to keep: once the user is gone the row is no longer personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_feedback', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->text('message')->nullable();

            /*
             * Which build said it. A one-star run of feedback that is all one
             * version and one platform is a release regression; the same
             * rating spread evenly is an opinion.
             */
            $table->string('platform', 16)->nullable();
            $table->string('app_version', 32)->nullable();

            $table->timestamps();

            // The admin list reads newest first and filters by score.
            $table->index(['created_at']);
            $table->index(['rating', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_feedback');
    }
};
