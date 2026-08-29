<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A rating left against one delivered order (EP12).
 *
 * Tied to the order rather than to the customer and the restaurant, so a
 * rating is always evidence that the person actually ordered the food.
 */
class Review extends Model
{
    protected $fillable = [
        'order_id', 'user_id', 'merchant_id', 'rider_id',
        'rating', 'rider_rating', 'comment',
    ];

    /*
     * The moderation columns are written by ReviewService, never mass
     * assigned — hiding a review is an act with an actor and a reason, not a
     * field on a form.
     */

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'rider_rating' => 'integer',
            'hidden_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReviewItem::class);
    }

    /** The moderator, when there was one. */
    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_by_user_id');
    }

    /**
     * Reviews that count.
     *
     * A hidden review keeps its row — it is evidence if a customer disputes
     * the takedown, and it defends a merchant accused of buying ratings — but
     * it must never reach a customer or an average.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('hidden_at');
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }
}
