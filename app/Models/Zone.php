<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Zone extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'city', 'state',
        'centre_latitude', 'centre_longitude',
        'radius_metres', 'max_radius_metres', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'centre_latitude' => 'decimal:7',
            'centre_longitude' => 'decimal:7',
            'radius_metres' => 'integer',
            'max_radius_metres' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class);
    }

    public function riders(): HasMany
    {
        return $this->hasMany(Rider::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
