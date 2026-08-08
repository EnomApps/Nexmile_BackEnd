<?php

namespace App\Http\Resources;

use App\Services\Media\ImageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category_name' => $this->whenLoaded('category', fn () => $this->category?->name),

            'name' => $this->name,
            'description' => $this->description,

            // Signed and expiring; fetch the item again for a fresh link.
            'image_url' => app(ImageService::class)->url($this->image_path),

            'price' => (float) $this->price,
            'compare_at_price' => $this->compare_at_price !== null ? (float) $this->compare_at_price : null,
            'is_discounted' => $this->isDiscounted(),
            'gst_rate' => (float) $this->gst_rate,

            'is_veg' => (bool) $this->is_veg,
            'contains_egg' => (bool) $this->contains_egg,
            'is_available' => (bool) $this->is_available,

            'prep_time_minutes' => (int) $this->prep_time_minutes,
            'sort_order' => (int) $this->sort_order,

            'option_groups' => ItemOptionGroupResource::collection($this->whenLoaded('optionGroups')),
        ];
    }
}
