<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One kept position from a rider's trail.
 *
 * Written once on a path that runs every ten seconds per on-duty rider, so
 * `UPDATED_AT` is off: an `updated_at` column on an append-only table is a
 * second write and a column nobody can ever read anything from.
 */
class RiderLocationPing extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'rider_id', 'order_id',
        'latitude', 'longitude',
        'heading', 'speed', 'accuracy_metres',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'heading' => 'decimal:2',
            'speed' => 'decimal:2',
            'accuracy_metres' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
