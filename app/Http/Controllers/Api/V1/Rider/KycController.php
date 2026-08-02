<?php

namespace App\Http\Controllers\Api\V1\Rider;

use App\Http\Controllers\Api\V1\Concerns\HandlesKycDocuments;
use App\Http\Controllers\Controller;
use App\Models\Rider;
use App\Services\Kyc\DocumentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rider KYC — Aadhaar, PAN, licence, RC and insurance (EP2).
 */
class KycController extends Controller
{
    use HandlesKycDocuments;

    /**
     * KYC status and documents
     */
    public function show(Request $request, DocumentService $service): JsonResponse
    {
        return response()->json(['data' => $this->kycStatusPayload($request, $service)]);
    }

    /**
     * Upload a KYC document
     *
     * Multipart form data with `type` and `file`. JPG, PNG or PDF up to 5 MB.
     */
    public function upload(Request $request, DocumentService $service): JsonResponse
    {
        return $this->uploadDocument($request, $service);
    }

    /**
     * Delete a document
     */
    public function destroy(Request $request, DocumentService $service, int $document): JsonResponse
    {
        return $this->destroyDocument($request, $service, $document);
    }

    /**
     * Submit for verification
     */
    public function submit(Request $request, DocumentService $service): JsonResponse
    {
        return $this->submitKyc($request, $service);
    }

    /**
     * Update document reference numbers
     *
     * The numbers printed on the documents. Locked once KYC is verified, so an
     * approved rider cannot swap in a different licence afterwards.
     */
    public function updateDetails(Request $request): JsonResponse
    {
        /** @var Rider $rider */
        $rider = $this->owner($request);

        if ($rider->isKycVerified()) {
            return response()->json([
                'message' => 'Your documents are verified and can no longer be edited. Contact support if something needs to change.',
            ], 422);
        }

        $data = $request->validate([
            'aadhaar_number' => ['sometimes', 'string', 'regex:/^\d{12}$/'],
            'pan' => ['sometimes', 'string', 'regex:/^[A-Z]{5}\d{4}[A-Z]$/'],
            'driving_licence_no' => ['sometimes', 'string', 'max:20'],
            'driving_licence_expiry' => ['sometimes', 'date', 'after:today'],
            'vehicle_number' => ['sometimes', 'string', 'max:15'],
            'rc_number' => ['sometimes', 'string', 'max:30'],
            'insurance_number' => ['sometimes', 'string', 'max:40'],
            'insurance_expiry' => ['sometimes', 'date', 'after:today'],
            'bank_account_name' => ['sometimes', 'string', 'max:255'],
            'bank_account_number' => ['sometimes', 'string', 'max:30'],
            'bank_ifsc' => ['sometimes', 'string', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
        ], [
            'aadhaar_number.regex' => 'Aadhaar must be 12 digits.',
            'pan.regex' => 'Enter a valid PAN (e.g. ABCDE1234F).',
            'bank_ifsc.regex' => 'Enter a valid IFSC code.',
            'driving_licence_expiry.after' => 'This licence has already expired.',
            'insurance_expiry.after' => 'This insurance has already expired.',
        ]);

        $rider->update($data);

        return response()->json(['message' => 'Details updated.']);
    }

    protected function owner(Request $request): Model
    {
        $user = $request->user();

        return $user->rider ?? Rider::create([
            'user_id' => $user->id,
            'full_name' => $user->name,
        ]);
    }

    protected function role(): string
    {
        return 'rider';
    }
}
