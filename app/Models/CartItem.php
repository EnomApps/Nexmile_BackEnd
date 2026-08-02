<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CartItem extends Model
{
    use HasFactory;

    protected $fillable = ['cart_id', 'menu_item_id', 'quantity', 'notes'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(CartItemOption::class);
    }

    public function lineTotal(): float
    {
        $optionsTotal = (float) $this->options
            ->sum(fn (CartItemOption $option) => (float) ($option->itemOption?->price_delta ?? 0));

        return ((float) ($this->menuItem?->price ?? 0) + $optionsTotal) * $this->quantity;
    }
}
