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
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Business profile
            $table->string('business_name');
            $table->string('owner_name');
            $table->string('business_phone', 15)->nullable();
            $table->string('business_email')->nullable();

            // Location — drives the 1 km discovery radius (EP4)
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('city');
            $table->string('state')->default('Tamil Nadu');
            $table->string('pincode', 10);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // KYC documents — validity checks live in the service layer
            $table->string('fssai_license_no', 20)->nullable();
            $table->date('fssai_expiry_date')->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('pan', 10)->nullable();

            // Settlement account
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number', 30)->nullable();
            $table->string('bank_ifsc', 11)->nullable();

            $table->enum('kyc_status', ['pending', 'submitted', 'verified', 'rejected'])
                ->default('pending')
                ->index();
            $table->text('kyc_rejection_reason')->nullable();
            $table->timestamp('kyc_verified_at')->nullable();

            $table->boolean('is_accepting_orders')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['latitude', 'longitude']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
