<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\KycStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\MenuItemResource;
use App\Http\Resources\RestaurantResource;
use App\Models\Address;
use App\Models\MenuItem;
use App\Models\Merchant;
use App\Services\Discovery\NearbyMerchantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Restaurant discovery and menu browsing for customers (EP3, EP4).
 *
 * Gated on `auth:sanctum` but **not** on a role. A rider ordering their dinner
 * and a merchant ordering from the shop opposite are both customers here —
 * see docs/ROLES.md.
 */
class RestaurantController extends Controller
{
    public function __construct(protected NearbyMerchantService $nearby) {}

    /**
     * Nearby restaurants
     *
     * Send either an `address_id` from the customer's address book, or a raw
     * `latitude` and `longitude` from device GPS.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address_id' => ['required_without_all:latitude,longitude', 'integer'],
            'latitude' => ['required_without:address_id', 'numeric', 'between:-90,90'],
            'longitude' => ['required_without:address_id', 'numeric', 'between:-180,180'],
            'service_category' => ['sometimes', 'string', 'max:30'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ], [
            'address_id.required_without_all' => 'Send an address_id, or latitude and longitude.',
        ]);

        [$latitude, $longitude] = $this->resolvePoint($request, $data);

        $results = $this->nearby->search(
            $latitude,
            $longitude,
            $data['service_category'] ?? null,
            $data['search'] ?? null,
            $data['per_page'] ?? null,
        );

        return RestaurantResource::collection($results)
            ->additional(['meta' => [
                'radius_metres' => $this->nearby->radiusFor($latitude, $longitude),
            ]])
            ->response();
    }

    /**
     * Food Rescue deals nearby
     *
     * Surplus food a kitchen would otherwise throw away, discounted and
     * finite. Only deals that are actually orderable right now — inside their
     * window with portions left — so the screen never advertises something
     * checkout would refuse.
     */
    public function deals(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address_id' => ['required_without_all:latitude,longitude', 'integer'],
            'latitude' => ['required_without:address_id', 'numeric', 'between:-90,90'],
            'longitude' => ['required_without:address_id', 'numeric', 'between:-180,180'],
        ], [
            'address_id.required_without_all' => 'Send an address_id, or latitude and longitude.',
        ]);

        [$latitude, $longitude] = $this->resolvePoint($request, $data);

        // Reuse discovery so the radius, verification and open-first rules
        // cannot drift from the main restaurant list.
        $nearby = $this->nearby->search($latitude, $longitude, perPage: 50);

        $items = MenuItem::query()
            ->whereIn('merchant_id', collect($nearby->items())->pluck('id'))
            ->available()
            ->surplusActive()
            ->where('surplus_quantity', '>', 0)
            ->with(['merchant', 'optionGroups.options'])
            ->orderBy('surplus_available_until')
            ->get();

        return response()->json([
            'data' => $items->map(fn (MenuItem $item) => [
                'restaurant' => [
                    'id' => $item->merchant->id,
                    'name' => $item->merchant->business_name,
                    'is_open' => $item->merchant->isOpenNow(),
                ],
                'item' => new MenuItemResource($item),
            ])->values(),
            // Soonest to expire first — a rescue deal is a countdown.
            'meta' => ['radius_metres' => $this->nearby->radiusFor($latitude, $longitude)],
        ]);
    }

    /**
     * Restaurant details
     */
    public function show(int $restaurant): JsonResponse
    {
        return response()->json([
            'data' => new RestaurantResource(
                $this->findServiceable($restaurant, ['operatingHours']),
            ),
        ]);
    }

    /**
     * Restaurant menu
     *
     * Categories in the merchant's own order, each with its items.
     */
    public function menu(Request $request, int $restaurant): JsonResponse
    {
        $merchant = $this->findServiceable($restaurant, [
            'operatingHours',
            /*
             * Unavailable items are returned, not filtered out. Merchants
             * toggle these mid-service, and a dish silently vanishing confuses
             * the customer who came for it — the app shows it struck through.
             *
             * Inactive *categories* are hidden: that is the merchant
             * deliberately retiring a section, not a temporary shortage.
             */
            'categories' => fn ($q) => $q->active()->ordered(),
            'categories.menuItems' => fn ($q) => $q->ordered(),
            'categories.menuItems.optionGroups.options',
        ]);

        $loose = $merchant->menuItems()
            ->whereNull('category_id')
            ->with('optionGroups.options')
            ->ordered()
            ->get();

        $menu = CategoryResource::collection($merchant->categories)->toArray($request);

        /*
         * Loose items are appended to `menu` as a group with a null id rather
         * than returned alongside it.
         *
         * They used to be a sibling key, and every reader who looped `menu`
         * silently lost them — a shop that never made categories looked empty
         * while its dishes sat one key away. Documenting the trap did not stop
         * it happening; one list that is always complete does.
         */
        if ($loose->isNotEmpty()) {
            $menu[] = [
                'id' => null,
                'name' => __('portal.menu.uncategorised'),
                'description' => null,
                'sort_order' => 9999,
                'is_active' => true,
                'items' => MenuItemResource::collection($loose)->toArray($request),
            ];
        }

        return response()->json([
            'data' => [...(new RestaurantResource($merchant))->toArray($request), 'menu' => $menu],
        ]);
    }

    /**
     * A customer may only see verified storefronts. An unverified merchant is
     * not a restaurant yet — nobody has confirmed a licensed food business is
     * behind it — so the id must 404 rather than render.
     *
     * @param  array<int|string, mixed>  $with
     */
    protected function findServiceable(int $id, array $with = []): Merchant
    {
        return Merchant::query()
            ->with($with)
            ->where('kyc_status', KycStatus::Verified->value)
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{float, float}
     */
    protected function resolvePoint(Request $request, array $data): array
    {
        if (isset($data['latitude'], $data['longitude'])) {
            return [(float) $data['latitude'], (float) $data['longitude']];
        }

        // Scoped to the caller: another customer's address id is a 404, not a
        // window onto where they live.
        $address = Address::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($data['address_id']);

        abort_if(
            $address->latitude === null || $address->longitude === null,
            422,
            'This address has no location saved. Edit it and drop a pin.',
        );

        return [(float) $address->latitude, (float) $address->longitude];
    }
}
