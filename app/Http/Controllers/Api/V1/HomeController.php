<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RestaurantResource;
use App\Models\Banner;
use App\Models\Collection as CuratedCollection;
use App\Models\Cuisine;
use App\Services\Discovery\NearbyMerchantService;
use App\Services\Discovery\RestaurantFilters;
use App\Services\Media\ImageService;
use App\Support\HomeCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The customer home screen, as ordered sections.
 *
 * One call rather than five, and — the reason it is worth building — the
 * *order and presence* of the sections comes from the server. Adding a
 * seasonal rail or dropping a dead one is a database change, not a Play Store
 * submission and a week of waiting for people to update.
 *
 * The app skips a section `type` it does not recognise, so a new type can ship
 * here before the app supports it.
 */
class HomeController extends Controller
{
    use ResolvesCustomerLocation;

    public function __construct(protected NearbyMerchantService $nearby) {}

    /**
     * Home screen
     *
     * Send either an `address_id` from the customer's address book, or a raw
     * `latitude` and `longitude` from device GPS — the same rule as
     * `GET /restaurants`.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address_id' => ['required_without_all:latitude,longitude', 'integer'],
            'latitude' => ['required_without:address_id', 'numeric', 'between:-90,90'],
            'longitude' => ['required_without:address_id', 'numeric', 'between:-180,180'],
        ], [
            'address_id.required_without_all' => 'Send an address_id, or latitude and longitude.',
        ]);

        [$latitude, $longitude] = $this->resolvePoint($request, $data);

        $images = app(ImageService::class);

        /*
         * Banners, cuisines and collection tiles are the same for everyone and
         * change a few times a week, yet they are fetched on every app open.
         * Cached together under one key so a single admin edit clears all
         * three rather than leaving a half-stale screen.
         *
         * Deliberately not the restaurant sections: those depend on where the
         * customer is standing, and caching them would show one person another
         * person's neighbourhood.
         */
        $merchandising = Cache::remember(
            HomeCache::KEY,
            now()->addSeconds(HomeCache::ttlSeconds()),
            fn () => [
                'banners' => $this->banners($images),
                'cuisines' => $this->cuisines($images),
                'tiles' => $this->collectionTiles($images),
            ],
        );

        $sections = [];

        if ($merchandising['banners'] !== []) {
            $sections[] = ['type' => 'banners', 'items' => $merchandising['banners']];
        }

        if ($merchandising['cuisines'] !== []) {
            $sections[] = ['type' => 'cuisines', 'items' => $merchandising['cuisines']];
        }

        /*
         * Fetched once and reused by every restaurant section below. The
         * alternative is a nearby search per section, which is the same
         * bounding box and haversine run three times for one screen.
         */
        $nearby = $this->nearby
            ->search($latitude, $longitude, new RestaurantFilters(perPage: 50))
            ->getCollection();

        foreach ($merchandising['tiles'] as $tile) {
            $sections[] = $tile;
        }

        /*
         * "Recommended" is nearest-and-open, which is what relevance already
         * means here. Named separately from "Featured" so the ranking can
         * become something cleverer without the app changing.
         */
        if ($nearby->isNotEmpty()) {
            $sections[] = [
                'type' => 'restaurants',
                'title' => __('portal.home.recommended'),
                'layout' => 'grid',
                'items' => RestaurantResource::collection($nearby->take(10))->toArray($request),
            ];
        }

        $featured = $nearby->filter(fn ($m) => $m->rating !== null)
            ->sortByDesc('rating')
            ->take(10)
            ->values();

        if ($featured->isNotEmpty()) {
            $sections[] = [
                'type' => 'restaurants',
                'title' => __('portal.home.featured'),
                'layout' => 'list',
                'items' => RestaurantResource::collection($featured)->toArray($request),
            ];
        }

        return response()->json([
            'data' => ['sections' => $sections],
            'meta' => ['radius_metres' => $this->nearby->radiusFor($latitude, $longitude)],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function banners(ImageService $images): array
    {
        return Banner::live()->get()->map(fn (Banner $b) => [
            'id' => $b->id,
            'image_url' => $images->url($b->image_path),
            'alt_text' => $b->alt_text,
            'action' => array_filter([
                'type' => $b->action_type,
                'value' => $b->action_value,
            ], fn ($v) => $v !== null),
            'starts_at' => $b->starts_at,
            'ends_at' => $b->ends_at,
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function cuisines(ImageService $images): array
    {
        return Cuisine::live()->get()->map(fn (Cuisine $c) => [
            'slug' => $c->slug,
            'name' => $c->name,
            'image_url' => $images->url($c->image_path),
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function collectionTiles(ImageService $images): array
    {
        return CuratedCollection::live()
            ->where('show_on_home', true)
            ->get()
            ->map(fn (CuratedCollection $c) => [
                'type' => 'collection_tile',
                'title' => $c->title,
                'slug' => $c->slug,
                'image_url' => $images->url($c->banner_path),
            ])
            ->all();
    }
}
