<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rider profile and KYC (EP2). Live position is mirrored in Redis for dispatch;
 * the columns here are the last known fix, kept for audit and cold starts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('riders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();

            $table->string('full_name');
            $table->date('date_of_birth')->nullable();

            // KYC documents
            $table->string('aadhaar_number', 12)->nullable();
            $table->string('pan', 10)->nullable();
            $table->string('driving_licence_no', 20)->nullable();
            $table->date('driving_licence_expiry')->nullable();
            $table->string('vehicle_number', 15)->nullable();
            $table->enum('vehicle_type', ['bicycle', 'motorcycle', 'scooter', 'ev'])->default('motorcycle');
            $table->string('rc_number', 30)->nullable();
            $table->string('insurance_number', 40)->nullable();
            $table->date('insurance_expiry')->nullable();

            $table->enum('kyc_status', ['pending', 'submitted', 'verified', 'rejected'])
                ->default('pending')->index();
            $table->text('kyc_rejection_reason')->nullable();
            $table->timestamp('kyc_verified_at')->nullable();

            $table->enum('duty_status', ['offline', 'available', 'on_order', 'on_break'])
                ->default('offline')->index();
            $table->decimal('last_latitude', 10, 7)->nullable();
            $table->decimal('last_longitude', 10, 7)->nullable();
            $table->timestamp('last_location_at')->nullable();

            $table->unsignedInteger('completed_deliveries')->default(0);
            $table->decimal('rating', 3, 2)->nullable();

            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number', 30)->nullable();
            $table->string('bank_ifsc', 11)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Dispatch looks up available riders in a zone constantly.
            $table->index(['zone_id', 'duty_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('riders');
    }
};
