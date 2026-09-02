<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\KycStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RestaurantResource;
use App\Models\Collection as CuratedCollection;
use App\Services\Discovery\NearbyMerchantService;
use App\Services\Media\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Curated lists — the "Meals under 250" tile, and anywhere a banner points.
 */
class CollectionController extends Controller
{
    use ResolvesCustomerLocation;

    public function __construct(protected NearbyMerchantService $nearby) {}

    /**
     * A curated collection
     *
     * Takes the customer's location like the rest of discovery: a curated list
     * still has to respect the delivery radius, or it advertises restaurants
     * that cannot deliver to them.
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'address_id' => ['required_without_all:latitude,longitude', 'integer'],
            'latitude' => ['required_without:address_id', 'numeric', 'between:-90,90'],
            'longitude' => ['required_without:address_id', 'numeric', 'between:-180,180'],
        ], [
            'address_id.required_without_all' => 'Send an address_id, or latitude and longitude.',
        ]);

        [$latitude, $longitude] = $this->resolvePoint($request, $data);

        $collection = CuratedCollection::live()
            ->where('slug', $slug)
            ->firstOrFail();

        $radius = $this->nearby->radiusFor($latitude, $longitude);

        $restaurants = $collection->merchants()
            ->withLiveSurplusCount()
            ->where('kyc_status', KycStatus::Verified->value)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->each(fn ($m) => $m->distance_metres = $this->nearby->distance(
                $latitude, $longitude, (float) $m->latitude, (float) $m->longitude,
            ))
            // Curated, but not exempt: a shop 4 km away is still 4 km away.
            ->filter(fn ($m) => $m->distance_metres <= $radius)
            ->values();

        return response()->json([
            'data' => [
                'slug' => $collection->slug,
                'title' => $collection->title,
                'subtitle' => $collection->subtitle,
                'banner_url' => app(ImageService::class)->url($collection->banner_path),
                'restaurants' => RestaurantResource::collection($restaurants)->toArray($request),
            ],
            'meta' => ['radius_metres' => $radius, 'total' => $restaurants->count()],
        ]);
    }
}
