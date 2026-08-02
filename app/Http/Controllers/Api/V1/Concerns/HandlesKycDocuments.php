<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Enums\DocumentType;
use App\Http\Resources\KycDocumentResource;
use App\Models\KycDocument;
use App\Services\Kyc\DocumentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The merchant and rider KYC flows are identical apart from which documents
 * are required, so they share this rather than being written twice and
 * drifting apart.
 */
trait HandlesKycDocuments
{
    abstract protected function owner(Request $request): Model;

    /** 'merchant' or 'rider' — selects the config lists. */
    abstract protected function role(): string;

    protected function kycStatusPayload(Request $request, DocumentService $service): array
    {
        $owner = $this->owner($request);
        $missing = $service->missingDocuments($owner, $this->role());

        return [
            'status' => $owner->kyc_status,
            'rejection_reason' => $owner->kyc_rejection_reason,
            'verified_at' => $owner->kyc_verified_at,
            'required_documents' => config("kyc.required.{$this->role()}"),
            'allowed_documents' => array_map(
                fn (string $t) => ['type' => $t, 'label' => DocumentType::from($t)->label()],
                config("kyc.allowed.{$this->role()}")
            ),
            'missing_documents' => $missing,
            'can_submit' => $missing === [],
            'documents' => KycDocumentResource::collection(
                $owner->kycDocuments()->latest('id')->get()
            ),
        ];
    }

    protected function uploadDocument(Request $request, DocumentService $service): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', DocumentType::values())],
            'file' => [
                'required', 'file',
                'mimes:'.implode(',', config('kyc.allowed_mimes')),
                'max:'.config('kyc.max_file_size_kb'),
            ],
        ], [
            'file.max' => 'Files must be under '.round(config('kyc.max_file_size_kb') / 1024).' MB.',
            'file.mimes' => 'Upload a JPG, PNG or PDF.',
        ]);

        $document = $service->store(
            $this->owner($request),
            DocumentType::from($data['type']),
            $request->file('file'),
            $this->role(),
        );

        return response()->json([
            'message' => 'Document uploaded.',
            'data' => new KycDocumentResource($document),
        ], 201);
    }

    protected function destroyDocument(Request $request, DocumentService $service, int $documentId): JsonResponse
    {
        // Scoped to the owner: another account's document id returns 404.
        $document = $this->owner($request)->kycDocuments()->findOrFail($documentId);

        $service->delete($document);

        return response()->json(['message' => 'Document removed.']);
    }

    protected function submitKyc(Request $request, DocumentService $service): JsonResponse
    {
        $owner = $this->owner($request);

        $service->submitForReview($owner, $this->role());

        return response()->json([
            'message' => 'Submitted for verification. We usually review within two working days.',
            'data' => $this->kycStatusPayload($request, $service),
        ]);
    }

    protected function documentOr404(Model $owner, int $id): KycDocument
    {
        return $owner->kycDocuments()->findOrFail($id);
    }
}
