<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Api\V1\Concerns\ResolvesMerchant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\MenuItemRequest;
use App\Http\Resources\MenuItemResource;
use App\Services\Menu\MenuImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Menu items (EP3).
 *
 * Photos are sent as multipart on the same request as the rest of the item, so
 * a merchant adding a dish makes one call rather than create-then-upload.
 */
class MenuItemController extends Controller
{
    use ResolvesMerchant;

    public function __construct(protected MenuImageService $images) {}

    /**
     * List menu items
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'category_id' => ['sometimes', 'integer'],
            'is_available' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);

        $items = $this->merchant($request)
            ->menuItems()
            ->with('category')
            ->when(isset($filters['category_id']), fn ($q) => $q->where('category_id', $filters['category_id']))
            ->when(isset($filters['is_available']), fn ($q) => $q->where('is_available', $filters['is_available']))
            ->when(isset($filters['search']), fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%'))
            ->ordered()
            ->get();

        return response()->json(['data' => MenuItemResource::collection($items)]);
    }

    /**
     * Show one menu item
     */
    public function show(Request $request, int $item): JsonResponse
    {
        $model = $this->merchant($request)->menuItems()
            ->with(['category', 'optionGroups.options'])
            ->findOrFail($item);

        return response()->json(['data' => new MenuItemResource($model)]);
    }

    /**
     * Create a menu item
     */
    public function store(MenuItemRequest $request): JsonResponse
    {
        $data = $request->validated();
        unset($data['image']);

        $item = $this->merchant($request)->menuItems()->create($data);

        if ($request->hasFile('image')) {
            $this->images->attach($item, $request->file('image'));
        }

        return response()->json([
            'message' => 'Menu item created.',
            'data' => new MenuItemResource($item->fresh('category')),
        ], 201);
    }

    /**
     * Update a menu item
     */
    public function update(MenuItemRequest $request, int $item): JsonResponse
    {
        $model = $this->merchant($request)->menuItems()->findOrFail($item);

        $data = $request->validated();
        unset($data['image']);

        $model->update($data);

        if ($request->hasFile('image')) {
            $this->images->attach($model, $request->file('image'));
        }

        return response()->json([
            'message' => 'Menu item updated.',
            'data' => new MenuItemResource($model->fresh('category')),
        ]);
    }

    /**
     * Turn an item on or off
     *
     * Separate from update because this is the one action a merchant performs
     * mid-service, on a phone, when the kitchen runs out — it must not require
     * sending the whole item back.
     */
    public function setAvailability(Request $request, int $item): JsonResponse
    {
        $data = $request->validate([
            'is_available' => ['required', 'boolean'],
        ]);

        $model = $this->merchant($request)->menuItems()->findOrFail($item);
        $model->update($data);

        return response()->json([
            'message' => $data['is_available'] ? 'Item is back on the menu.' : 'Item marked out of stock.',
            'data' => new MenuItemResource($model->fresh('category')),
        ]);
    }

    /**
     * Remove a menu item's photo
     */
    public function destroyImage(Request $request, int $item): JsonResponse
    {
        $model = $this->merchant($request)->menuItems()->findOrFail($item);

        $this->images->detach($model);

        return response()->json([
            'message' => 'Photo removed.',
            'data' => new MenuItemResource($model->fresh('category')),
        ]);
    }

    /**
     * Delete a menu item
     *
     * Soft delete: order_items reference the menu item for reporting, and past
     * orders must keep resolving after a merchant tidies their menu.
     */
    public function destroy(Request $request, int $item): JsonResponse
    {
        $model = $this->merchant($request)->menuItems()->findOrFail($item);

        $model->delete();

        return response()->json(['message' => 'Menu item deleted.']);
    }

    /**
     * Reorder menu items
     *
     * Drag-and-drop sends the whole ordered list of ids in one call, so the
     * menu can never be left half-reordered by a dropped request.
     */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $merchant = $this->merchant($request);

        // Scoped update: ids belonging to another merchant simply match
        // nothing rather than silently reordering their menu.
        foreach (array_values($data['ids']) as $position => $id) {
            $merchant->menuItems()->whereKey($id)->update(['sort_order' => $position]);
        }

        return response()->json(['message' => 'Menu order saved.']);
    }
}
