<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RiderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'zone_id' => $this->zone_id,
            'vehicle' => [
                'type' => $this->vehicle_type,
                'number' => $this->vehicle_number,
            ],
            'kyc' => [
                'status' => $this->kyc_status,
                'rejection_reason' => $this->kyc_rejection_reason,
                'verified_at' => $this->kyc_verified_at,
                // Aadhaar and bank details are hidden on the model and never
                // returned; only whether they are on file.
                'pan' => $this->pan,
                'driving_licence_no' => $this->driving_licence_no,
                'driving_licence_expiry' => $this->driving_licence_expiry?->toDateString(),
                'insurance_expiry' => $this->insurance_expiry?->toDateString(),
                'documents_expired' => $this->hasExpiredDocuments(),
            ],
            'duty_status' => $this->duty_status,
            'can_accept_orders' => $this->canAcceptOrders(),
            'completed_deliveries' => $this->completed_deliveries,
            'rating' => $this->rating,
            'created_at' => $this->created_at,
        ];
    }
}
