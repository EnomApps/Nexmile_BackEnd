<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many people rated a rider, alongside the score.
 *
 * The score alone cannot be acted on: 2.0 from two ratings is noise, and 2.0
 * from forty is a conversation somebody needs to have. Without the count the
 * conduct flag would fire on the first bad night anyone had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riders', function (Blueprint $table) {
            $table->unsignedInteger('rating_count')->default(0)->after('rating');
        });
    }

    public function down(): void
    {
        Schema::table('riders', function (Blueprint $table) {
            $table->dropColumn('rating_count');
        });
    }
};
