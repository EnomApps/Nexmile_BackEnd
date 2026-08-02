<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A line on an order. Name, price and tax rate are snapshots taken when the
 * order was placed — renaming or repricing a menu item must never alter a past
 * order or the invoice generated from it.
 */
class OrderItem extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'options_total' => 'decimal:2',
            'line_total' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'quantity' => 'integer',
            'is_veg' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(OrderItemOption::class);
    }
}
