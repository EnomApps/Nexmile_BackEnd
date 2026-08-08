<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\OptionGroupRequest;
use App\Models\ItemOptionGroup;
use App\Models\MenuItem;
use App\Models\Merchant;
use App\Services\Menu\OptionGroupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Item customisation in the merchant portal (EP3).
 *
 * Shares OptionGroupRequest with the JSON API, so the portal cannot accept a
 * group the API would reject.
 */
class MerchantOptionController extends Controller
{
    public function __construct(protected OptionGroupService $groups) {}

    public function index(Request $request, int $item): View
    {
        $model = $this->item($request, $item);

        return view('merchants.menu.options', [
            'merchant' => $model->merchant,
            'item' => $model,
            'groups' => $model->optionGroups()->with('options')->orderBy('sort_order')->get(),
        ]);
    }

    public function store(OptionGroupRequest $request, int $item): RedirectResponse
    {
        $this->groups->create($this->item($request, $item), $request->validated());

        return back()->with('status', __('portal.options.group_created'));
    }

    public function update(OptionGroupRequest $request, int $group): RedirectResponse
    {
        $this->groups->update($this->group($request, $group), $request->validated());

        return back()->with('status', __('portal.options.group_saved'));
    }

    public function destroy(Request $request, int $group): RedirectResponse
    {
        $this->group($request, $group)->delete();

        return back()->with('status', __('portal.options.group_deleted'));
    }

    protected function item(Request $request, int $item): MenuItem
    {
        return $this->merchant($request)->menuItems()->findOrFail($item);
    }

    protected function group(Request $request, int $group): ItemOptionGroup
    {
        return ItemOptionGroup::query()
            ->whereHas('menuItem', fn ($q) => $q->where('merchant_id', $this->merchant($request)->id))
            ->findOrFail($group);
    }

    protected function merchant(Request $request): Merchant
    {
        $merchant = $request->user()->merchant;

        abort_if($merchant === null, 404);

        return $merchant;
    }
}
