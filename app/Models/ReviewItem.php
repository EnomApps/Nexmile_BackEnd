<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One dish rated inside one review (EP12). */
class ReviewItem extends Model
{
    protected $fillable = ['review_id', 'menu_item_id', 'rating'];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }
}
