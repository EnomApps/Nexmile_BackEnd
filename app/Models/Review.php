<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'rider_rating' => 'integer',
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
}
