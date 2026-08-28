<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A photo per menu category.
 *
 * A menu of text headings is a list to read. A photo against "Biryani" or
 * "Tiffin" is what a customer scans for, and it is the one thing a merchant
 * can change that reliably moves orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
