<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\KycStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RestaurantResource;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bookmarked restaurants — the heart on a restaurant card.
 */
class FavouriteController extends Controller
{
    /**
     * Favourite restaurants
     */
    public function index(Request $request): JsonResponse
    {
        $merchants = $request->user()->favourites()
            ->withLiveSurplusCount()
            ->where('kyc_status', KycStatus::Verified->value)
            ->orderByDesc('favourites.created_at')
            ->get();

        return RestaurantResource::collection($merchants)->response();
    }

    /**
     * Add a favourite
     *
     * Idempotent: tapping twice leaves one row, not two.
     */
    public function store(Request $request, int $restaurant): JsonResponse
    {
        $request->user()->favourites()->syncWithoutDetaching([$this->restaurant($restaurant)->id]);

        return response()->json(['message' => 'Saved.']);
    }

    /**
     * Remove a favourite
     */
    public function destroy(Request $request, int $restaurant): JsonResponse
    {
        $request->user()->favourites()->detach($restaurant);

        return response()->json(['message' => 'Removed.']);
    }

    private function restaurant(int $id): Merchant
    {
        // Same gate as discovery: a customer cannot bookmark a shop they are
        // not allowed to see.
        return Merchant::where('kyc_status', KycStatus::Verified->value)->findOrFail($id);
    }
}
