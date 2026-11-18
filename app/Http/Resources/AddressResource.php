<?php

namespace App\Http\Resources;

use App\Services\Media\ImageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'landmark' => $this->landmark,

            /*
             * Signed and short-lived; fetch the address again for a fresh
             * link. Null when the customer has not taken one, which the app
             * reads as "offer to add a photo", not as an error.
             */
            'photo_url' => app(ImageService::class)->doorUrl($this->photo_path),

            'city' => $this->city,
            'state' => $this->state,
            'pincode' => $this->pincode,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'is_default' => $this->is_default,
            'created_at' => $this->created_at,
        ];
    }
}
