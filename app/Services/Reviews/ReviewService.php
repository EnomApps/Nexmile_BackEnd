<?php

namespace App\Services\Reviews;

use App\Enums\OrderStatus;
use App\Models\MenuItem;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Review;
use App\Models\ReviewItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leaving and aggregating ratings (EP12).
 *
 * The aggregate on the merchant is the number every restaurant card shows, so
 * it is recalculated from the table rather than nudged arithmetically — an
 * incremental average drifts the moment one review is edited or deleted, and
 * the drift is invisible.
 */
class ReviewService
{
    /**
     * Ratings needed before a restaurant shows a score at all.
     *
     * One five-star review from the owner's cousin is not a rating, and a
     * single bad night should not brand a new kitchen 1.0 forever.
     */
    public const MIN_RATINGS_TO_PUBLISH = 3;

    /**
     * @throws ValidationException
     */
    /**
     * @param  array<int, int>  $dishRatings  menu_item_id => 1..5
     */
    public function leave(
        Order $order,
        User $actor,
        int $rating,
        ?int $riderRating,
        ?string $comment,
        array $dishRatings = [],
    ): Review {
        if ($order->user_id !== $actor->id) {
            throw ValidationException::withMessages([
                'order' => 'You can only review your own orders.',
            ]);
        }

        if ($order->status !== OrderStatus::Delivered) {
            throw ValidationException::withMessages([
                'order' => 'You can review an order once it has been delivered.',
            ]);
        }

        if ($order->review()->exists()) {
            throw ValidationException::withMessages([
                'order' => 'You have already reviewed this order.',
            ]);
        }

        $review = DB::transaction(function () use ($order, $actor, $rating, $riderRating, $comment, $dishRatings) {
            $review = Review::create([
                'order_id' => $order->id,
                'user_id' => $actor->id,
                'merchant_id' => $order->merchant_id,
                // Only where a rider actually carried it: rating nobody for a
                // collected order would be rating thin air.
                'rider_id' => $order->rider_id,
                'rating' => $rating,
                'rider_rating' => $order->rider_id === null ? null : $riderRating,
                'comment' => $comment,
            ]);

            /*
             * Only dishes that were actually on this order. Otherwise an
             * account could rate every dish in a restaurant from one ₹30 idli,
             * which is the cheapest way there is to bury a competitor.
             */
            $ordered = $order->items()->pluck('menu_item_id')->filter()->all();

            foreach ($dishRatings as $menuItemId => $score) {
                if (! in_array((int) $menuItemId, $ordered, true)) {
                    continue;
                }

                $review->items()->create([
                    'menu_item_id' => (int) $menuItemId,
                    'rating' => (int) $score,
                ]);

                $this->recalculateDish(MenuItem::find($menuItemId));
            }

            $this->recalculate($order->merchant);

            return $review;
        });

        return $review;
    }

    /**
     * Recompute a merchant's published rating from its reviews.
     */
    public function recalculate(Merchant $merchant): void
    {
        $row = Review::query()
            ->where('merchant_id', $merchant->id)
            // A hidden review is evidence, not a rating. Leaving it in the
            // average would make moderation cosmetic.
            ->visible()
            ->selectRaw('COUNT(*) as total, AVG(rating) as average')
            ->first();

        $count = (int) ($row->total ?? 0);

        $merchant->forceFill([
            'rating_count' => $count,
            // Null rather than a number nobody should act on yet.
            'rating' => $count >= self::MIN_RATINGS_TO_PUBLISH
                ? round((float) $row->average, 2)
                : null,
        ])->save();
    }

    /**
     * Recompute one dish's rating from its ratings.
     *
     * Same threshold as a restaurant, and for the same reason: a dish showing
     * "5.0" off a single review is noise wearing the clothes of a signal, and
     * it is the number a customer trusts most because it is the most specific.
     */
    public function recalculateDish(?MenuItem $item): void
    {
        if ($item === null) {
            return;
        }

        $row = ReviewItem::query()
            ->where('menu_item_id', $item->id)
            ->whereHas('review', fn ($q) => $q->visible())
            ->selectRaw('COUNT(*) as total, AVG(rating) as average')
            ->first();

        $count = (int) ($row->total ?? 0);

        $item->forceFill([
            'rating_count' => $count,
            'rating' => $count >= self::MIN_RATINGS_TO_PUBLISH
                ? round((float) $row->average, 2)
                : null,
        ])->save();
    }

    /**
     * Hide a review, and recompute everything it was propping up.
     */
    public function hide(Review $review, User $actor, string $reason): void
    {
        DB::transaction(function () use ($review, $actor, $reason) {
            $review->forceFill([
                'hidden_at' => now(),
                'hidden_by_user_id' => $actor->id,
                'hidden_reason' => $reason,
            ])->save();

            $this->afterModeration($review);
        });
    }

    /** Put one back, when it should not have gone. */
    public function unhide(Review $review): void
    {
        DB::transaction(function () use ($review) {
            $review->forceFill([
                'hidden_at' => null,
                'hidden_by_user_id' => null,
                'hidden_reason' => null,
            ])->save();

            $this->afterModeration($review);
        });
    }

    private function afterModeration(Review $review): void
    {
        $this->recalculate($review->merchant);

        // Every dish it rated moves too, or a hidden review keeps holding a
        // dish average up from behind a curtain.
        foreach ($review->items as $item) {
            $this->recalculateDish($item->menuItem);
        }
    }
}
