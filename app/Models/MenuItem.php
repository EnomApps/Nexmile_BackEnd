<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MenuItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'merchant_id', 'category_id', 'name', 'description', 'image_path',
        'price', 'compare_at_price', 'gst_rate',
        'is_veg', 'contains_egg', 'is_available',
        'prep_time_minutes', 'sort_order',
        'is_surplus_deal', 'surplus_available_from', 'surplus_available_until', 'surplus_quantity',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'is_veg' => 'boolean',
            'contains_egg' => 'boolean',
            'is_available' => 'boolean',
            'is_surplus_deal' => 'boolean',
            'prep_time_minutes' => 'integer',
            'sort_order' => 'integer',
            'surplus_quantity' => 'integer',
            'surplus_available_from' => 'datetime',
            'surplus_available_until' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function optionGroups(): HasMany
    {
        return $this->hasMany(ItemOptionGroup::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }

    /** A Food Rescue deal is only orderable inside its window (EP14). */
    public function scopeSurplusActive(Builder $query): Builder
    {
        return $query->where('is_surplus_deal', true)
            ->where(fn ($q) => $q->whereNull('surplus_available_from')->orWhere('surplus_available_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('surplus_available_until')->orWhere('surplus_available_until', '>=', now()));
    }

    public function isDiscounted(): bool
    {
        return $this->compare_at_price !== null && $this->compare_at_price > $this->price;
    }
}
