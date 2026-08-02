<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'merchant_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Live total. Carts reference menu items rather than snapshotting them, so
     * a price change while the customer is still shopping is reflected here.
     * Orders snapshot instead.
     */
    public function subtotal(): float
    {
        return (float) $this->items
            ->sum(fn (CartItem $item) => $item->lineTotal());
    }
}
