<?php

namespace App\Http\Resources;

use App\Services\Media\ImageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A storefront as a customer sees it (EP4).
 *
 * Deliberately narrower than MerchantResource: no bank details, no KYC record,
 * no owner name, no commission rate. Those belong to the merchant and to
 * admin, and a public list is the easiest place to leak them by accident.
 */
class RestaurantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $images = app(ImageService::class);

        return [
            'id' => $this->id,
            'name' => $this->business_name,
            'service_category' => $this->service_category,
            'description' => $this->description,

            'logo_url' => $images->url($this->logo_path),
            'banner_url' => $images->url($this->banner_path),

            'is_open' => $this->isOpenNow(),
            // Split out so the app can say *why* it is shut. "Closed for the
            // night" and "temporarily not taking orders" are different
            // messages, and only one of them is worth waiting for.
            'is_accepting_orders' => (bool) $this->is_accepting_orders,
            'within_operating_hours' => $this->isWithinOperatingHours(),

            'avg_prep_time_minutes' => (int) $this->avg_prep_time_minutes,
            'packaging_fee' => (float) $this->packaging_fee,
            'min_order_value' => (float) $this->min_order_value,
            'supports_pickup' => (bool) $this->supports_pickup,

            // Present only on a nearby search; null when fetched directly.
            'distance_metres' => $this->when(
                isset($this->distance_metres),
                fn () => (int) round($this->distance_metres),
            ),

            'area' => $this->city,

            'operating_hours' => $this->whenLoaded('operatingHours', fn () => $this->operatingHours
                ->sortBy('day_of_week')
                ->values()
                ->map(fn ($row) => [
                    'day_of_week' => (int) $row->day_of_week,
                    'is_closed' => (bool) $row->is_closed,
                    'opens_at' => $row->opens_at,
                    'closes_at' => $row->closes_at,
                ])),

            'menu' => CategoryResource::collection($this->whenLoaded('categories')),
        ];
    }
}
