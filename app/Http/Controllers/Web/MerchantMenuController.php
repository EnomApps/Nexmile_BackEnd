<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\CategoryRequest;
use App\Http\Requests\Merchant\MenuItemRequest;
use App\Models\MenuItem;
use App\Models\Merchant;
use App\Services\Media\ImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Menu management in the merchant portal (EP3).
 *
 * Shares its validation with the JSON API through the same FormRequests, so
 * the two cannot accept different data.
 */
class MerchantMenuController extends Controller
{
    public function __construct(protected ImageService $images) {}

    public function index(Request $request): View
    {
        $merchant = $this->merchant($request);

        return view('merchants.menu.index', [
            'merchant' => $merchant,
            'categories' => $merchant->categories()->withCount('menuItems')->ordered()->get(),
            // Grouped in the view; a single query keeps the page flat.
            'items' => $merchant->menuItems()->with('category')->withCount('optionGroups')->ordered()->get(),
            'images' => $this->images,
        ]);
    }

    public function createItem(Request $request): View
    {
        $merchant = $this->merchant($request);

        return view('merchants.menu.item-form', [
            'merchant' => $merchant,
            'item' => new MenuItem(['is_veg' => true, 'is_available' => true, 'gst_rate' => 5.00, 'prep_time_minutes' => 10]),
            'categories' => $merchant->categories()->ordered()->get(),
            'imageUrl' => null,
        ]);
    }

    public function editItem(Request $request, int $item): View
    {
        $merchant = $this->merchant($request);
        $model = $merchant->menuItems()->findOrFail($item);

        return view('merchants.menu.item-form', [
            'merchant' => $merchant,
            'item' => $model,
            'categories' => $merchant->categories()->ordered()->get(),
            'imageUrl' => $this->images->url($model->image_path),
        ]);
    }

    public function storeItem(MenuItemRequest $request): RedirectResponse
    {
        $data = $request->validated();
        unset($data['image']);

        $item = $this->merchant($request)->menuItems()->create($data);

        if ($request->hasFile('image')) {
            $this->images->attach($item, 'image_path', $item->photoDirectory(), $request->file('image'));
        }

        return redirect()->route('merchants.menu.index')->with('status', __('portal.menu.item_created'));
    }

    public function updateItem(MenuItemRequest $request, int $item): RedirectResponse
    {
        $model = $this->merchant($request)->menuItems()->findOrFail($item);

        $data = $request->validated();
        unset($data['image']);

        $model->update($data);

        if ($request->hasFile('image')) {
            $this->images->attach($model, 'image_path', $model->photoDirectory(), $request->file('image'));
        }

        return redirect()->route('merchants.menu.index')->with('status', __('portal.menu.item_saved'));
    }

    /**
     * The mid-service action: one click from the list, no form.
     */
    public function toggleItem(Request $request, int $item): RedirectResponse
    {
        $model = $this->merchant($request)->menuItems()->findOrFail($item);

        $model->update(['is_available' => ! $model->is_available]);

        return back()->with('status', $model->is_available
            ? __('portal.menu.item_available')
            : __('portal.menu.item_unavailable'));
    }

    public function destroyItem(Request $request, int $item): RedirectResponse
    {
        $this->merchant($request)->menuItems()->findOrFail($item)->delete();

        return back()->with('status', __('portal.menu.item_deleted'));
    }

    public function storeCategory(CategoryRequest $request): RedirectResponse
    {
        $this->merchant($request)->categories()->create($request->validated());

        return back()->with('status', __('portal.menu.category_created'));
    }

    public function destroyCategory(Request $request, int $category): RedirectResponse
    {
        $model = $this->merchant($request)->categories()->findOrFail($category);

        // Items survive; they just lose their grouping.
        $model->menuItems()->update(['category_id' => null]);
        $model->delete();

        return back()->with('status', __('portal.menu.category_deleted'));
    }

    /**
     * Scoped to the signed-in merchant, so another merchant's item id is a
     * 404 rather than an edit.
     */
    protected function merchant(Request $request): Merchant
    {
        $merchant = $request->user()->merchant;

        abort_if($merchant === null, 404);

        return $merchant;
    }
}
