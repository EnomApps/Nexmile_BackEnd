<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_name' => $this->business_name,
            'owner_name' => $this->owner_name,
            'business_phone' => $this->business_phone,
            'business_email' => $this->business_email,
            'address' => [
                'line1' => $this->address_line1,
                'line2' => $this->address_line2,
                'city' => $this->city,
                'state' => $this->state,
                'pincode' => $this->pincode,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
            ],
            'kyc' => [
                'status' => $this->kyc_status,
                'rejection_reason' => $this->kyc_rejection_reason,
                'verified_at' => $this->kyc_verified_at,
                'fssai_license_no' => $this->fssai_license_no,
                'fssai_expiry_date' => $this->fssai_expiry_date?->toDateString(),
                'fssai_valid' => $this->hasValidFssai(),
                'gstin' => $this->gstin,
                'pan' => $this->pan,
            ],
            'is_accepting_orders' => $this->is_accepting_orders,
            'created_at' => $this->created_at,
        ];
    }
}
