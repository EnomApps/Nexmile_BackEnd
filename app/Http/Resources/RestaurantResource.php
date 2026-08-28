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
    /** Where the per-request favourites memo is kept. */
    private const FAVOURITES_KEY = 'nexmile.favourite_ids';

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

            /*
             * Card and filter fields (home screen v2).
             *
             * rating is null, never 0.0, until enough people have rated —
             * "0.0" reads as bad where a new kitchen is only new, and the app
             * hides the badge on null.
             */
            'rating' => $this->rating === null ? null : (float) $this->rating,
            'rating_count' => (int) $this->rating_count,
            'is_pure_veg' => (bool) $this->is_pure_veg,
            'cost_for_two' => $this->cost_for_two === null ? null : (int) $this->cost_for_two,
            'cuisines' => array_values($this->cuisines ?? []),

            // Derived from what the pricing code actually does, not stored:
            // an offer nobody honours is worse than no offer.
            'offers' => $this->offers(),
            'has_free_delivery' => $this->hasFreeDelivery(),

            // Only meaningful for a signed-in customer, which every route
            // returning this resource requires.
            'is_favourite' => in_array($this->id, self::favouriteIds($request), true),

            /*
             * Why this restaurant matched, on a search only. A result reading
             * "Hotel Vasanth" for a search of "dosa" leaves the customer to
             * open the menu and hunt for it; naming the dish and its price is
             * what makes the result worth trusting.
             *
             * Absent when the restaurant matched on its own name — there is
             * nothing to explain when the name is what was typed.
             */
            'matched_dishes' => $this->when(
                ! empty($this->matched_dishes),
                fn () => $this->matched_dishes,
            ),
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

    /**
     * The caller's favourite restaurant ids, fetched once per request.
     *
     * Asking per card would be one query per restaurant on the busiest list in
     * the product — twenty cards, twenty round trips, for a bookmark icon.
     *
     * @return list<int>
     */
    private static function favouriteIds(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        /*
         * Memoised on the request, not in a static.
         *
         * A static survives the process, not the request. Under a persistent
         * worker that means a customer bookmarks a restaurant and the icon
         * stays empty until the worker recycles — and it is exactly what made
         * this test pass alone and fail in the suite.
         */
        if (! $request->attributes->has(self::FAVOURITES_KEY)) {
            $request->attributes->set(self::FAVOURITES_KEY, $user->favourites()
                ->pluck('merchants.id')
                ->map(fn ($id) => (int) $id)
                ->all());
        }

        return $request->attributes->get(self::FAVOURITES_KEY);
    }
}
