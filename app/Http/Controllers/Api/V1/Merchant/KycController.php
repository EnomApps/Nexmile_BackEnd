<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Api\V1\Concerns\HandlesKycDocuments;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Services\Kyc\DocumentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Merchant KYC — FSSAI, GST, PAN and bank proof (EP2).
 */
class KycController extends Controller
{
    use HandlesKycDocuments;

    /**
     * KYC status and documents
     *
     * Shows what has been uploaded, what is still missing, and whether the
     * account can be submitted for review.
     */
    public function show(Request $request, DocumentService $service): JsonResponse
    {
        return response()->json(['data' => $this->kycStatusPayload($request, $service)]);
    }

    /**
     * Upload a KYC document
     *
     * Multipart form data with `type` and `file`. JPG, PNG or PDF up to 5 MB.
     * Uploading a type that already exists replaces it, unless it has already
     * been approved.
     */
    public function upload(Request $request, DocumentService $service): JsonResponse
    {
        return $this->uploadDocument($request, $service);
    }

    /**
     * Delete a document
     *
     * Only while pending or rejected. Approved documents are the evidence
     * behind a verification decision and stay on file.
     */
    public function destroy(Request $request, DocumentService $service, int $document): JsonResponse
    {
        return $this->destroyDocument($request, $service, $document);
    }

    /**
     * Submit for verification
     *
     * Refused until every required document is uploaded.
     */
    public function submit(Request $request, DocumentService $service): JsonResponse
    {
        return $this->submitKyc($request, $service);
    }

    protected function owner(Request $request): Model
    {
        $merchant = $request->user()->merchant;

        abort_if($merchant === null, 404, 'No business profile found for this account.');

        return $merchant;
    }

    protected function role(): string
    {
        return 'merchant';
    }
}
