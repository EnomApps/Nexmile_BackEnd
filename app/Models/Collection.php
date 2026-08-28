<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A curated list of restaurants — "Meals under 250". */
class Collection extends Model
{
    protected $fillable = [
        'slug', 'title', 'subtitle', 'banner_path',
        'position', 'is_active', 'show_on_home',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_on_home' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function merchants(): BelongsToMany
    {
        return $this->belongsToMany(Merchant::class)
            ->withPivot('position')
            ->orderBy('collection_merchant.position');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('position');
    }
}
