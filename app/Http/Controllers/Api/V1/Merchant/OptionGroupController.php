<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Api\V1\Concerns\ResolvesMerchant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\OptionGroupRequest;
use App\Http\Resources\ItemOptionGroupResource;
use App\Models\ItemOption;
use App\Models\ItemOptionGroup;
use App\Models\MenuItem;
use App\Services\Menu\OptionGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Item option groups (EP3) — "Spice level", "Add-ons", "Choose your rice".
 *
 * Without these, half a menu cannot be expressed: a dosa with no filling
 * choice and a biryani with no portion size are different products sold under
 * one price.
 */
class OptionGroupController extends Controller
{
    use ResolvesMerchant;

    public function __construct(protected OptionGroupService $groups) {}

    /**
     * List an item's option groups
     */
    public function index(Request $request, int $item): JsonResponse
    {
        $model = $this->item($request, $item);

        return response()->json([
            'data' => ItemOptionGroupResource::collection(
                $model->optionGroups()->with('options')->orderBy('sort_order')->get(),
            ),
        ]);
    }

    /**
     * Create an option group
     *
     * Options are sent with the group, not afterwards.
     */
    public function store(OptionGroupRequest $request, int $item): JsonResponse
    {
        $group = $this->groups->create($this->item($request, $item), $request->validated());

        return response()->json([
            'message' => 'Option group added.',
            'data' => new ItemOptionGroupResource($group),
        ], 201);
    }

    /**
     * Update an option group
     *
     * Sending `options` replaces the list: entries with an `id` are updated,
     * new entries are created, and anything absent is removed.
     */
    public function update(OptionGroupRequest $request, int $group): JsonResponse
    {
        $model = $this->groups->update($this->group($request, $group), $request->validated());

        return response()->json([
            'message' => 'Option group updated.',
            'data' => new ItemOptionGroupResource($model),
        ]);
    }

    /**
     * Delete an option group
     */
    public function destroy(Request $request, int $group): JsonResponse
    {
        $this->group($request, $group)->delete();

        return response()->json(['message' => 'Option group deleted.']);
    }

    /**
     * Turn one option on or off
     *
     * The mid-service action: the kitchen runs out of paneer, not of the whole
     * "Choose your filling" group.
     */
    public function setOptionAvailability(Request $request, int $option): JsonResponse
    {
        $data = $request->validate([
            'is_available' => ['required', 'boolean'],
        ]);

        $model = ItemOption::query()
            ->whereHas('group.menuItem', fn ($q) => $q->where('merchant_id', $this->merchant($request)->id))
            ->findOrFail($option);

        $model->update($data);

        return response()->json([
            'message' => $data['is_available'] ? 'Option is available again.' : 'Option marked unavailable.',
        ]);
    }

    /**
     * Scoped through the merchant, so another merchant's item id is a 404.
     */
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
}
