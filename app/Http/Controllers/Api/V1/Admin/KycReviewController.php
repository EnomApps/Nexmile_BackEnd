<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DocumentStatus;
use App\Enums\KycStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\KycDocumentResource;
use App\Http\Resources\MerchantResource;
use App\Http\Resources\RiderResource;
use App\Models\KycDocument;
use App\Models\Merchant;
use App\Models\Rider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin KYC review (EP2, EP13).
 *
 * Every decision records who made it and when — a verification is a
 * compliance record, and "who approved this restaurant" must be answerable.
 */
class KycReviewController extends Controller
{
    /**
     * Accounts awaiting review
     *
     * Merchants and riders that have submitted documents, oldest first so the
     * queue is worked in order.
     */
    public function queue(Request $request): JsonResponse
    {
        $status = $request->string('status', KycStatus::Submitted->value)->toString();

        $merchants = Merchant::where('kyc_status', $status)
            ->with('user')->oldest('updated_at')->limit(100)->get();

        $riders = Rider::where('kyc_status', $status)
            ->with('user')->oldest('updated_at')->limit(100)->get();

        return response()->json([
            'data' => [
                'merchants' => MerchantResource::collection($merchants),
                'riders' => RiderResource::collection($riders),
                'counts' => [
                    'merchants' => $merchants->count(),
                    'riders' => $riders->count(),
                ],
            ],
        ]);
    }

    /**
     * Documents for one account
     */
    public function documents(string $type, int $id): JsonResponse
    {
        $owner = $this->resolveOwner($type, $id);

        return response()->json([
            'data' => KycDocumentResource::collection(
                $owner->kycDocuments()->latest('id')->get()
            ),
        ]);
    }

    /**
     * Approve or reject a single document
     */
    public function reviewDocument(Request $request, int $document): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'rejection_reason' => ['required_if:status,rejected', 'nullable', 'string', 'max:500'],
        ], [
            'rejection_reason.required_if' => 'Tell the applicant why it was rejected, so they can fix it.',
        ]);

        $model = KycDocument::findOrFail($document);

        $model->update([
            'status' => DocumentStatus::from($data['status']),
            'rejection_reason' => $data['status'] === 'rejected' ? $data['rejection_reason'] : null,
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Document '.$data['status'].'.',
            'data' => new KycDocumentResource($model->fresh()),
        ]);
    }

    /**
     * Verify an account
     *
     * Activates the account. A merchant still has to open their storefront
     * themselves, and a rider still has to go on duty.
     */
    public function verify(Request $request, string $type, int $id): JsonResponse
    {
        $owner = $this->resolveOwner($type, $id);

        // Approving an account while a document is still rejected would leave
        // the record self-contradictory.
        $rejected = $owner->kycDocuments()->where('status', DocumentStatus::Rejected->value)->count();

        if ($rejected > 0) {
            return response()->json([
                'message' => 'Resolve the rejected documents before verifying this account.',
            ], 422);
        }

        DB::transaction(function () use ($owner, $request) {
            $owner->kycDocuments()
                ->where('status', DocumentStatus::Pending->value)
                ->update([
                    'status' => DocumentStatus::Approved->value,
                    'reviewed_by_user_id' => $request->user()->id,
                    'reviewed_at' => now(),
                ]);

            $owner->update([
                'kyc_status' => KycStatus::Verified,
                'kyc_rejection_reason' => null,
                'kyc_verified_at' => now(),
            ]);

            $owner->user?->update(['status' => UserStatus::Active]);
        });

        return response()->json([
            'message' => 'Account verified.',
            'data' => $this->present($owner->fresh()),
        ]);
    }

    /**
     * Reject an account
     *
     * The reason is shown to the applicant, so it has to say what to fix.
     */
    public function reject(Request $request, string $type, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], [
            'reason.min' => 'Give a reason the applicant can act on.',
        ]);

        $owner = $this->resolveOwner($type, $id);

        $owner->update([
            'kyc_status' => KycStatus::Rejected,
            'kyc_rejection_reason' => $data['reason'],
            'kyc_verified_at' => null,
        ]);

        return response()->json([
            'message' => 'Account rejected.',
            'data' => $this->present($owner->fresh()),
        ]);
    }

    /**
     * Suspend or reinstate an account
     */
    public function setUserStatus(Request $request, string $type, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,suspended'],
        ]);

        $owner = $this->resolveOwner($type, $id);
        $status = UserStatus::from($data['status']);

        DB::transaction(function () use ($owner, $status) {
            $owner->user?->update(['status' => $status]);

            // A suspended merchant must stop receiving orders immediately,
            // not when they next happen to open the dashboard.
            if ($status === UserStatus::Suspended && $owner instanceof Merchant) {
                $owner->update(['is_accepting_orders' => false]);
            }

            if ($status === UserStatus::Suspended) {
                $owner->user?->tokens()->delete();
            }
        });

        return response()->json([
            'message' => $status === UserStatus::Suspended ? 'Account suspended.' : 'Account reinstated.',
            'data' => $this->present($owner->fresh()),
        ]);
    }

    private function resolveOwner(string $type, int $id): Model
    {
        return match ($type) {
            'merchants' => Merchant::findOrFail($id),
            'riders' => Rider::findOrFail($id),
            default => abort(404),
        };
    }

    private function present(Model $owner): MerchantResource|RiderResource
    {
        return $owner instanceof Merchant
            ? new MerchantResource($owner)
            : new RiderResource($owner);
    }
}
