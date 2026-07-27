<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status,
            'preferred_locale' => $this->preferred_locale,
            'phone_verified' => $this->phone_verified_at !== null,
            'merchant' => new MerchantResource($this->whenLoaded('merchant')),
        ];
    }
}
