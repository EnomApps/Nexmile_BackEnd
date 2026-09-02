<?php

namespace App\Models;

use App\Models\Concerns\ClearsHomeCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** One tile on the cuisine rail — Biryani, Cake, Dosa. */
class Cuisine extends Model
{
    use ClearsHomeCache;

    protected $fillable = ['slug', 'name', 'image_path', 'position', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'position' => 'integer'];
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('position');
    }
}
