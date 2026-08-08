<?php

namespace App\Http\Resources;

use App\Services\Media\ImageService;
use App\Services\Menu\SurplusService;
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

            /*
             * Food Rescue (EP14). `is_rescue_deal` is only true while the deal
             * is actually orderable — inside its window with portions left —
             * so an app never advertises a deal checkout would refuse.
             */
            'is_rescue_deal' => app(SurplusService::class)->isLive($this->resource),
            'rescue' => $this->when((bool) $this->is_surplus_deal, fn () => [
                'portions_left' => (int) $this->surplus_quantity,
                'available_from' => $this->surplus_available_from,
                'available_until' => $this->surplus_available_until,
                'saving' => $this->compare_at_price !== null
                    ? round((float) $this->compare_at_price - (float) $this->price, 2)
                    : 0.0,
            ]),

            'option_groups' => ItemOptionGroupResource::collection($this->whenLoaded('optionGroups')),
        ];
    }
}
