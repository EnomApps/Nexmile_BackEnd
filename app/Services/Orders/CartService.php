<?php

namespace App\Services\Orders;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ItemOption;
use App\Models\ItemOptionGroup;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The server-side cart (EP5).
 *
 * One cart per customer per merchant, enforced by a unique key. Mixing shops
 * in one cart would break the single-pickup model the 1 km radius is built on,
 * and clearing a basket when someone glances at another restaurant is the kind
 * of thing that loses an order.
 *
 * Carts hold live references to menu items so prices stay current while the
 * customer is still shopping. Orders snapshot instead.
 */
class CartService
{
    public function __construct(protected PricingService $pricing) {}

    public function forMerchant(User $user, int $merchantId): Cart
    {
        return Cart::firstOrCreate(['user_id' => $user->id, 'merchant_id' => $merchantId]);
    }

    /**
     * Every cart the customer has open, newest first.
     *
     * @return Collection<int, Cart>
     */
    public function all(User $user): Collection
    {
        return Cart::query()
            ->where('user_id', $user->id)
            ->whereHas('items')
            ->with(['merchant', 'items.menuItem', 'items.options.itemOption.group'])
            ->latest('updated_at')
            ->get();
    }

    /**
     * Add an item, or increase the quantity of an identical line.
     *
     * "Identical" means the same dish with the same chosen options and the
     * same note. Two chai with different sugar levels are two lines; two
     * identical chai are one line of quantity 2, because a cart listing the
     * same thing twice looks like a bug to the person holding the phone.
     *
     * @param  list<int>  $optionIds
     *
     * @throws ValidationException
     */
    public function add(Cart $cart, int $menuItemId, int $quantity, array $optionIds = [], ?string $notes = null): CartItem
    {
        $menuItem = $this->resolveItem($cart, $menuItemId);
        $options = $this->validateOptions($menuItem, $optionIds);

        return DB::transaction(function () use ($cart, $menuItem, $quantity, $options, $notes) {
            $existing = $this->matchingLine($cart, $menuItem->id, $options->pluck('id')->all(), $notes);

            if ($existing) {
                return $this->setQuantity($cart, $existing, $existing->quantity + $quantity);
            }

            $line = $cart->items()->create([
                'menu_item_id' => $menuItem->id,
                'quantity' => $this->guardQuantity($quantity),
                'notes' => $notes,
            ]);

            foreach ($options as $option) {
                $line->options()->create(['item_option_id' => $option->id]);
            }

            $cart->touch();

            return $line->load('options.itemOption');
        });
    }

    /**
     * @throws ValidationException
     */
    public function setQuantity(Cart $cart, CartItem $item, int $quantity): CartItem
    {
        if ($quantity < 1) {
            $this->remove($cart, $item);

            return $item;
        }

        $item->update(['quantity' => $this->guardQuantity($quantity)]);
        $cart->touch();

        return $item->fresh('options.itemOption');
    }

    public function remove(Cart $cart, CartItem $item): void
    {
        $item->delete();
        $cart->touch();
    }

    /**
     * Empty the cart but keep the row: it costs nothing and saves a lookup
     * when the customer adds something again.
     */
    public function clear(Cart $cart): void
    {
        $cart->items()->delete();
        $cart->touch();
    }

    /**
     * Items that can no longer be ordered — the merchant took them off the
     * menu or ran out while the cart was sitting there.
     *
     * Surfaced rather than silently dropped: a basket that quietly shrinks
     * between screens is worse than one that explains itself.
     *
     * @return list<string>
     */
    public function unavailableItems(Cart $cart): array
    {
        $cart->loadMissing('items.menuItem');

        return $cart->items
            ->filter(fn (CartItem $item) => $item->menuItem === null || ! $item->menuItem->is_available)
            ->map(fn (CartItem $item) => $item->menuItem->name ?? 'An item')
            ->values()
            ->all();
    }

    /**
     * @throws ValidationException
     */
    protected function resolveItem(Cart $cart, int $menuItemId): MenuItem
    {
        $item = MenuItem::query()
            ->where('merchant_id', $cart->merchant_id)
            ->find($menuItemId);

        if ($item === null) {
            // Scoped to the cart's merchant, so an item from another shop is
            // "not on this menu" rather than quietly mixing two kitchens.
            throw ValidationException::withMessages([
                'menu_item_id' => 'That item is not on this restaurant\'s menu.',
            ]);
        }

        if (! $item->is_available) {
            throw ValidationException::withMessages([
                'menu_item_id' => "{$item->name} is out of stock right now.",
            ]);
        }

        return $item;
    }

    /**
     * Check the chosen options against the item's groups.
     *
     * This is where a required choice is enforced. Leaving it to checkout
     * would mean the customer discovers at the last step that a dish they
     * added five minutes ago was never valid.
     *
     * @param  list<int>  $optionIds
     * @return Collection<int, ItemOption>
     *
     * @throws ValidationException
     */
    protected function validateOptions(MenuItem $menuItem, array $optionIds): Collection
    {
        $groups = $menuItem->optionGroups()->with('options')->get();

        /** @var Collection<int, ItemOption> $chosen */
        $chosen = ItemOption::query()
            ->whereIn('id', array_unique($optionIds))
            ->whereIn('item_option_group_id', $groups->pluck('id'))
            ->with('group')
            ->get();

        if ($chosen->count() !== count(array_unique($optionIds))) {
            throw ValidationException::withMessages([
                'option_ids' => 'One of those choices is not available for this item.',
            ]);
        }

        if ($unavailable = $chosen->firstWhere('is_available', false)) {
            throw ValidationException::withMessages([
                'option_ids' => "{$unavailable->name} is not available right now.",
            ]);
        }

        foreach ($groups as $group) {
            $this->guardGroup($group, $chosen->where('item_option_group_id', $group->id)->count());
        }

        return $chosen;
    }

    /**
     * @throws ValidationException
     */
    protected function guardGroup(ItemOptionGroup $group, int $count): void
    {
        if ($group->is_required && $count < max(1, $group->min_selections)) {
            throw ValidationException::withMessages([
                'option_ids' => "Choose an option for {$group->name}.",
            ]);
        }

        if (! $group->is_required && $count === 0) {
            return;
        }

        if ($count < $group->min_selections) {
            throw ValidationException::withMessages([
                'option_ids' => "Choose at least {$group->min_selections} for {$group->name}.",
            ]);
        }

        $max = $group->selection === 'single' ? 1 : $group->max_selections;

        if ($max !== null && $count > $max) {
            throw ValidationException::withMessages([
                'option_ids' => $max === 1
                    ? "Choose only one option for {$group->name}."
                    : "Choose at most {$max} for {$group->name}.",
            ]);
        }
    }

    /**
     * @param  list<int>  $optionIds
     */
    protected function matchingLine(Cart $cart, int $menuItemId, array $optionIds, ?string $notes): ?CartItem
    {
        sort($optionIds);

        return $cart->items()
            ->where('menu_item_id', $menuItemId)
            ->where('notes', $notes)
            ->with('options')
            ->get()
            ->first(function (CartItem $line) use ($optionIds) {
                $existing = $line->options->pluck('item_option_id')->sort()->values()->all();

                return $existing === $optionIds;
            });
    }

    /**
     * @throws ValidationException
     */
    protected function guardQuantity(int $quantity): int
    {
        $max = (int) config('checkout.max_quantity_per_item');

        if ($quantity > $max) {
            throw ValidationException::withMessages([
                'quantity' => "You can order at most {$max} of one item. Call the restaurant for a larger order.",
            ]);
        }

        return $quantity;
    }
}
