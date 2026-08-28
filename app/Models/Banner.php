<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A home-screen carousel slide. */
class Banner extends Model
{
    protected $fillable = [
        'image_path', 'alt_text', 'action_type', 'action_value',
        'starts_at', 'ends_at', 'position', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** Switched on, and inside its campaign window if it has one. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('position');
    }
}
