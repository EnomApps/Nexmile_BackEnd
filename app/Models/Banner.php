<?php

namespace App\Models;

use App\Models\Concerns\ClearsHomeCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A home-screen carousel slide. */
class Banner extends Model
{
    use ClearsHomeCache;

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
            /*
             * A banner with no stored image renders as a hole in the
             * carousel. Rows like that exist from before attach() started
             * failing loudly, so the scope skips them rather than trusting
             * that none were ever created.
             */
            ->whereNotNull('image_path')
            ->where('image_path', '!=', '')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('position');
    }
}
