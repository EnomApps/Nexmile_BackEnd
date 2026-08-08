<?php

namespace App\Services\Menu;

use App\Models\MenuItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Food Rescue (EP14).
 *
 * Surplus food a kitchen would otherwise throw away, offered at a discount
 * inside a window and in a fixed quantity. It is the thing that makes Nexmile
 * more than a small delivery app, and the marketing site has been promising it
 * since launch.
 *
 * A rescue deal is an ordinary menu item wearing a hat: same dish, same
 * kitchen, temporarily cheaper and finite. Modelling it as a separate product
 * would duplicate every option group and every photo.
 */
class SurplusService
{
    /**
     * Turn a dish into a rescue deal.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function offer(MenuItem $item, array $data): MenuItem
    {
        $from = Carbon::parse($data['surplus_available_from']);
        $until = Carbon::parse($data['surplus_available_until']);

        if ($until->lessThanOrEqualTo($from)) {
            throw ValidationException::withMessages([
                'surplus_available_until' => 'The deal must end after it starts.',
            ]);
        }

        if ($until->isPast()) {
            throw ValidationException::withMessages([
                'surplus_available_until' => 'That window has already passed.',
            ]);
        }

        /*
         * The original price is kept in compare_at_price so the customer sees
         * what they are saving. Without it a rescue deal is just a cheap dish,
         * and the whole point is that it is visibly a rescue.
         */
        $original = (float) ($data['compare_at_price'] ?? $item->compare_at_price ?? $item->price);
        $price = (float) $data['price'];

        if ($price >= $original) {
            throw ValidationException::withMessages([
                'price' => 'A rescue deal has to be cheaper than the usual price.',
            ]);
        }

        $item->update([
            'is_surplus_deal' => true,
            'price' => $price,
            'compare_at_price' => $original,
            'surplus_available_from' => $from,
            'surplus_available_until' => $until,
            'surplus_quantity' => (int) $data['surplus_quantity'],
            // A deal nobody can order is not a deal.
            'is_available' => true,
        ]);

        return $item->fresh();
    }

    /**
     * Put the dish back to normal, restoring the price it was rescued from.
     */
    public function withdraw(MenuItem $item): MenuItem
    {
        $item->update([
            'is_surplus_deal' => false,
            'price' => $item->compare_at_price ?? $item->price,
            'compare_at_price' => null,
            'surplus_available_from' => null,
            'surplus_available_until' => null,
            'surplus_quantity' => null,
        ]);

        return $item->fresh();
    }

    /**
     * Whether this deal can be ordered right now.
     */
    public function isLive(MenuItem $item, ?Carbon $at = null): bool
    {
        if (! $item->is_surplus_deal) {
            return false;
        }

        $at ??= now();

        if ($item->surplus_available_from && $at->lessThan($item->surplus_available_from)) {
            return false;
        }

        if ($item->surplus_available_until && $at->greaterThan($item->surplus_available_until)) {
            return false;
        }

        return (int) $item->surplus_quantity > 0;
    }

    /**
     * Take portions off a live deal.
     *
     * A single conditional UPDATE, for the same reason the rider claim is one:
     * two customers buying the last two portions at the same instant must not
     * both succeed. Reading the count and then writing it back would let them.
     *
     * @throws ValidationException
     */
    public function claim(MenuItem $item, int $quantity): void
    {
        if (! $item->is_surplus_deal) {
            return;
        }

        $claimed = DB::table('menu_items')
            ->where('id', $item->id)
            ->where('is_surplus_deal', true)
            ->where('surplus_quantity', '>=', $quantity)
            ->where(fn ($q) => $q->whereNull('surplus_available_from')->orWhere('surplus_available_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('surplus_available_until')->orWhere('surplus_available_until', '>=', now()))
            ->decrement('surplus_quantity', $quantity);

        if ($claimed !== 1) {
            throw ValidationException::withMessages([
                'cart' => "{$item->name} has sold out or the deal has ended. Remove it to continue.",
            ]);
        }
    }

    /**
     * Give portions back when an order is cancelled before anyone cooked it.
     *
     * Not restored after delivery for obvious reasons, and not restored on a
     * rejection either — by then the kitchen has usually already used the food
     * or binned it, and inventing stock that no longer exists is worse than
     * losing a sale.
     */
    public function release(MenuItem $item, int $quantity): void
    {
        if (! $item->is_surplus_deal) {
            return;
        }

        DB::table('menu_items')
            ->where('id', $item->id)
            ->where('is_surplus_deal', true)
            ->increment('surplus_quantity', $quantity);
    }
}
