<?php

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KYC documents for merchants and riders (EP2).
 *
 * Only metadata lives here; the file itself sits on a private S3 bucket. The
 * path is never exposed — reads go through short-lived signed URLs, because
 * these are Aadhaar, PAN and bank records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();

            // Polymorphic: a merchant or a rider owns the document.
            $table->morphs('documentable');

            $table->enum('type', DocumentType::values());
            $table->enum('status', DocumentStatus::values())
                ->default(DocumentStatus::Pending->value)->index();

            $table->string('disk', 30)->default('s3');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');

            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // "Has this owner uploaded a current PAN card?" is the hot query.
            $table->index(['documentable_type', 'documentable_id', 'type'], 'kyc_owner_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_documents');
    }
};
