<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Api\V1\Concerns\ResolvesMerchant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\CategoryRequest;
use App\Http\Resources\CategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Menu categories (EP3) — "Starters", "Biryani", "Beverages".
 */
class CategoryController extends Controller
{
    use ResolvesMerchant;

    /**
     * List categories
     */
    public function index(Request $request): JsonResponse
    {
        $categories = $this->merchant($request)
            ->categories()
            ->withCount('menuItems')
            ->ordered()
            ->get();

        return response()->json(['data' => CategoryResource::collection($categories)]);
    }

    /**
     * Create a category
     */
    public function store(CategoryRequest $request): JsonResponse
    {
        $category = $this->merchant($request)->categories()->create($request->validated());

        return response()->json([
            'message' => 'Category created.',
            'data' => new CategoryResource($category),
        ], 201);
    }

    /**
     * Update a category
     */
    public function update(CategoryRequest $request, int $category): JsonResponse
    {
        $model = $this->merchant($request)->categories()->findOrFail($category);

        $model->update($request->validated());

        return response()->json([
            'message' => 'Category updated.',
            'data' => new CategoryResource($model->fresh()),
        ]);
    }

    /**
     * Delete a category
     *
     * Items in the category are kept and become uncategorised. Deleting a
     * merchant's dishes because they reorganised their menu would be a data
     * loss they never asked for.
     */
    public function destroy(Request $request, int $category): JsonResponse
    {
        $model = $this->merchant($request)->categories()->findOrFail($category);

        $model->menuItems()->update(['category_id' => null]);
        $model->delete();

        return response()->json(['message' => 'Category deleted. Its items are now uncategorised.']);
    }
}
