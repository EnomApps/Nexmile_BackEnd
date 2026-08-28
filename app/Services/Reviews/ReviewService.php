<?php

namespace App\Services\Reviews;

use App\Enums\OrderStatus;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Review;
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
    public function leave(Order $order, User $actor, int $rating, ?int $riderRating, ?string $comment): Review
    {
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

        $review = DB::transaction(function () use ($order, $actor, $rating, $riderRating, $comment) {
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
}
