<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 15)->nullable()->unique()->after('name');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->enum('role', ['customer', 'rider', 'merchant', 'admin'])
                ->default('customer')
                ->after('password')
                ->index();
            $table->enum('status', ['pending', 'active', 'suspended'])
                ->default('pending')
                ->after('role')
                ->index();
            $table->string('preferred_locale', 5)->default('en')->after('status');
            $table->softDeletes();
        });

        // Phone is the primary credential for customer/rider, so email must be optional.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropIndex(['role']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'phone',
                'phone_verified_at',
                'role',
                'status',
                'preferred_locale',
                'deleted_at',
            ]);
        });
    }
};
