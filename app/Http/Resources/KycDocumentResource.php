<?php

namespace App\Http\Resources;

use App\Enums\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KycDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->type instanceof DocumentType ? $this->type : DocumentType::from($this->type);

        return [
            'id' => $this->id,
            'type' => $type->value,
            'label' => $type->label(),
            'status' => $this->status,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => $this->reviewed_at,
            // Expires in minutes; fetch the record again for a fresh link.
            'download_url' => $this->temporaryUrl(),
            'uploaded_at' => $this->created_at,
        ];
    }
}
