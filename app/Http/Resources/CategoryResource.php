<?php

namespace App\Http\Resources;

use App\Services\Media\ImageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => app(ImageService::class)->url($this->image_path),
            'sort_order' => (int) $this->sort_order,
            'is_active' => (bool) $this->is_active,
            'items_count' => $this->whenCounted('menuItems'),
            'items' => MenuItemResource::collection($this->whenLoaded('menuItems')),
        ];
    }
}
