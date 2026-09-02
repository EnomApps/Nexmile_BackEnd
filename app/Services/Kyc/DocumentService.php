<?php

namespace App\Services\Kyc;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\KycStatus;
use App\Models\KycDocument;
use App\Models\Rider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores and reviews KYC documents (EP2).
 *
 * Files go to a private disk. Nothing here ever returns a permanent URL.
 */
class DocumentService
{
    /**
     * Validation for an uploaded document, shared by the API and the merchant
     * portal so the two cannot drift apart.
     *
     * @param  list<string>  $types  document types the caller's role may send
     * @return array<string, mixed>
     */
    public static function uploadRules(array $types): array
    {
        return [
            'type' => ['required', 'string', 'in:'.implode(',', $types)],
            'file' => [
                'required', 'file',
                'mimes:'.implode(',', config('kyc.allowed_mimes')),
                'max:'.config('kyc.max_file_size_kb'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function uploadMessages(): array
    {
        $mb = round(config('kyc.max_file_size_kb') / 1024);

        return [
            'file.max' => "Files must be under {$mb} MB.",
            'file.mimes' => 'Upload a JPG, PNG or PDF.',

            /*
             * PHP throws away a file larger than upload_max_filesize before
             * Laravel sees it, so the max rule above never runs. The default
             * message for that case is "The file failed to upload." — true,
             * but it tells the merchant nothing they can act on.
             */
            'file.uploaded' => "That file is too large to upload. Keep it under {$mb} MB.",
        ];
    }

    /**
     * Replace or add a document of a given type.
     *
     * Uploading a type that already exists supersedes the old file, so an
     * owner never accumulates three PAN cards and leaves a reviewer guessing
     * which one is current.
     *
     * @throws ValidationException
     */
    public function store(Model $owner, DocumentType $type, UploadedFile $file, string $role): KycDocument
    {
        $this->guardTypeAllowed($type, $role);

        $existing = $this->currentDocument($owner, $type);

        if ($existing && ! $existing->canBeReplaced()) {
            throw ValidationException::withMessages([
                'type' => 'This document has already been approved and cannot be replaced. Contact support if it needs to change.',
            ]);
        }

        $disk = config('kyc.disk');

        /*
         * A random filename, not the uploaded one. User-supplied names can
         * carry path separators or a second extension, and they also leak
         * personal information into the object key.
         */
        $name = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
        $directory = sprintf('kyc/%s/%d', $role, $owner->getKey());

        $path = $file->storeAs($directory, $name, ['disk' => $disk, 'visibility' => 'private']);

        return DB::transaction(function () use ($owner, $type, $file, $path, $disk, $existing) {
            // Soft delete first so the unique-current-document view stays clean.
            $existing?->delete();

            return $owner->kycDocuments()->create([
                'type' => $type,
                'status' => DocumentStatus::Pending,
                'disk' => $disk,
                'path' => $path,
                'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
            ]);
        });
    }

    /**
     * A rider must not be able to attach an FSSAI certificate, and a merchant
     * must not attach a driving licence — the reviewer's checklist depends on
     * document types matching the role.
     *
     * @throws ValidationException
     */
    private function guardTypeAllowed(DocumentType $type, string $role): void
    {
        if (! in_array($type->value, config("kyc.allowed.{$role}", []), true)) {
            throw ValidationException::withMessages([
                'type' => "{$type->label()} is not required for this account type.",
            ]);
        }
    }

    public function currentDocument(Model $owner, DocumentType $type): ?KycDocument
    {
        return $owner->kycDocuments()->where('type', $type->value)->latest('id')->first();
    }

    /**
     * Remove a document the owner uploaded. Approved documents stay — they are
     * the evidence behind a verification decision.
     *
     * @throws ValidationException
     */
    public function delete(KycDocument $document): void
    {
        if (! $document->canBeReplaced()) {
            throw ValidationException::withMessages([
                'document' => 'Approved documents cannot be deleted.',
            ]);
        }

        Storage::disk($document->disk)->delete($document->path);
        $document->delete();
    }

    /**
     * Which required documents are still missing.
     *
     * @return array<int, string>
     */
    public function missingDocuments(Model $owner, string $role): array
    {
        /*
         * A rider on foot or a bicycle is held to a shorter list: there is no
         * licence, registration or insurance to produce. Asking for them would
         * leave those riders stuck in onboarding with nothing to upload.
         */
        $key = $role === 'rider' && $owner instanceof Rider && ! $owner->isMotorised()
            ? 'rider_unmotorised'
            : $role;

        $required = config("kyc.required.{$key}", []);

        $uploaded = $owner->kycDocuments()
            ->whereIn('status', [DocumentStatus::Pending->value, DocumentStatus::Approved->value])
            ->pluck('type')
            ->map(fn ($t) => $t instanceof DocumentType ? $t->value : $t)
            ->all();

        return array_values(array_diff($required, $uploaded));
    }

    /**
     * Move an owner from draft to "awaiting review".
     *
     * @throws ValidationException
     */
    public function submitForReview(Model $owner, string $role): void
    {
        if ($owner->kyc_status === KycStatus::Verified) {
            throw ValidationException::withMessages([
                'kyc' => 'Your account is already verified.',
            ]);
        }

        $missing = $this->missingDocuments($owner, $role);

        if ($missing !== []) {
            $labels = array_map(
                fn (string $t) => DocumentType::from($t)->label(),
                $missing
            );

            throw ValidationException::withMessages([
                'documents' => 'Upload these before submitting: '.implode(', ', $labels).'.',
            ]);
        }

        $owner->update([
            'kyc_status' => KycStatus::Submitted,
            'kyc_rejection_reason' => null,
        ]);
    }
}
