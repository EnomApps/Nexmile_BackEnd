<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItemOption extends Model
{
    use HasFactory;

    protected $fillable = ['cart_item_id', 'item_option_id'];

    public function cartItem(): BelongsTo
    {
        return $this->belongsTo(CartItem::class);
    }

    public function itemOption(): BelongsTo
    {
        return $this->belongsTo(ItemOption::class);
    }
}
