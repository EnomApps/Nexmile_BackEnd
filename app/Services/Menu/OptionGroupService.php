<?php

namespace App\Services\Menu;

use App\Models\ItemOptionGroup;
use App\Models\MenuItem;
use Illuminate\Support\Facades\DB;

/**
 * Item customisation: "Spice level", "Add-ons", "Choose your rice" (EP3, EP5).
 *
 * A group and its options are written together. Splitting them across two
 * calls would leave a window where a group exists with no choices, and an
 * item carrying such a group cannot be ordered at all.
 */
class OptionGroupService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(MenuItem $item, array $data): ItemOptionGroup
    {
        return DB::transaction(function () use ($item, $data) {
            $group = $item->optionGroups()->create($this->groupAttributes($data));

            $this->syncOptions($group, $data['options'] ?? []);

            return $group->load('options');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ItemOptionGroup $group, array $data): ItemOptionGroup
    {
        return DB::transaction(function () use ($group, $data) {
            $group->update($this->groupAttributes($data));

            if (array_key_exists('options', $data)) {
                $this->syncOptions($group, $data['options']);
            }

            return $group->fresh('options');
        });
    }

    /**
     * Reconcile the submitted list against what is stored.
     *
     * Options carrying an `id` are updated in place rather than replaced.
     * Recreating them would break the link from historical order lines — those
     * snapshot the name and price, so an order stays readable either way, but
     * "how often was extra cheese ordered" stops being answerable.
     *
     * @param  array<int, array<string, mixed>>  $options
     */
    protected function syncOptions(ItemOptionGroup $group, array $options): void
    {
        $keptIds = [];

        foreach (array_values($options) as $position => $option) {
            $attributes = [
                'name' => $option['name'],
                'price_delta' => $option['price_delta'] ?? 0,
                'is_available' => $option['is_available'] ?? true,
                'sort_order' => $option['sort_order'] ?? $position,
            ];

            /*
             * Scoped to this group: an id from another item's group would
             * otherwise be adopted, silently moving a competitor's option.
             */
            $existing = isset($option['id'])
                ? $group->options()->whereKey($option['id'])->first()
                : null;

            if ($existing) {
                $existing->update($attributes);
                $keptIds[] = $existing->id;

                continue;
            }

            $keptIds[] = $group->options()->create($attributes)->id;
        }

        // Anything the merchant removed from the form.
        $group->options()->whereNotIn('id', $keptIds)->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function groupAttributes(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'name', 'selection', 'is_required', 'min_selections', 'max_selections', 'sort_order',
        ]));
    }
}
