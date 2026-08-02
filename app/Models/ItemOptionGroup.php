<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemOptionGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'menu_item_id', 'name', 'selection', 'is_required',
        'min_selections', 'max_selections', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'min_selections' => 'integer',
            'max_selections' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ItemOption::class);
    }

    public function allowsMultiple(): bool
    {
        return $this->selection === 'multiple';
    }
}
