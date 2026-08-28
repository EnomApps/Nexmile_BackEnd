<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Cuisine;
use App\Services\Reviews\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The filter sheet, defined by the server.
 *
 * Same argument as the home sections: a new filter, a new price bracket or a
 * reworded label ships without an app release. `key` matches the query
 * parameter on `GET /restaurants` exactly, so the app can build the request
 * from this response without a lookup table of its own.
 *
 * Titles and labels are localised here using the caller's locale, the same way
 * `status_label` works on orders.
 */
class FilterController extends Controller
{
    /**
     * Filter definitions
     */
    public function index(Request $request): JsonResponse
    {
        $cuisines = Cuisine::live()->get()
            ->map(fn (Cuisine $c) => ['value' => $c->slug, 'label' => $c->name])
            ->all();

        $filters = [
            [
                'key' => 'sort',
                'title' => __('portal.filters.sort'),
                'type' => 'single_choice',
                'options' => [
                    ['value' => 'relevance', 'label' => __('portal.filters.sort_relevance')],
                    ['value' => 'rating', 'label' => __('portal.filters.sort_rating')],
                    ['value' => 'delivery_time', 'label' => __('portal.filters.sort_delivery_time')],
                    ['value' => 'cost_low_high', 'label' => __('portal.filters.sort_cost_low')],
                    ['value' => 'cost_high_low', 'label' => __('portal.filters.sort_cost_high')],
                ],
            ],
            [
                'key' => 'rating_min',
                'title' => __('portal.filters.rating'),
                'type' => 'single_choice',
                'options' => [
                    ['value' => '3.5', 'label' => __('portal.filters.rated_min', ['score' => '3.5'])],
                    ['value' => '4.0', 'label' => __('portal.filters.rated_min', ['score' => '4.0'])],
                ],
            ],
            [
                'key' => 'cost_for_two',
                'title' => __('portal.filters.cost'),
                'type' => 'range_choice',
                'options' => [
                    ['value' => '0-150', 'label' => __('portal.filters.cost_under', ['amount' => 150])],
                    ['value' => '150-300', 'label' => '₹150 – ₹300'],
                    ['value' => '300-', 'label' => __('portal.filters.cost_over', ['amount' => 300])],
                ],
            ],
            [
                'key' => 'veg_only',
                'title' => __('portal.filters.veg_only'),
                'type' => 'toggle',
                'options' => [],
            ],
            [
                'key' => 'open_now',
                'title' => __('portal.filters.open_now'),
                'type' => 'toggle',
                'options' => [],
            ],
            [
                'key' => 'has_offers',
                'title' => __('portal.filters.has_offers'),
                'type' => 'toggle',
                'options' => [],
            ],
            [
                'key' => 'near_and_fast',
                'title' => __('portal.filters.near_and_fast'),
                'type' => 'toggle',
                'options' => [],
            ],
            [
                'key' => 'no_packaging_fee',
                'title' => __('portal.filters.no_packaging_fee'),
                'type' => 'toggle',
                'options' => [],
            ],
        ];

        /*
         * Free delivery is currently a platform-wide threshold, so a filter
         * for it would match every restaurant and mean nothing. Offered only
         * once it can actually separate them.
         */
        if (config('checkout.free_delivery_varies_by_merchant', false)) {
            $filters[] = [
                'key' => 'free_delivery',
                'title' => __('portal.filters.free_delivery'),
                'type' => 'toggle',
                'options' => [],
            ];
        }

        // Only worth a sheet section once there are cuisines to choose from.
        if ($cuisines !== []) {
            array_splice($filters, 2, 0, [[
                'key' => 'cuisine',
                'title' => __('portal.filters.cuisine'),
                'type' => 'multi_choice',
                'options' => $cuisines,
            ]]);
        }

        return response()->json([
            'data' => $filters,
            'meta' => [
                // So the app can explain a hidden badge rather than guessing.
                'min_ratings_to_publish' => ReviewService::MIN_RATINGS_TO_PUBLISH,
            ],
        ]);
    }
}
