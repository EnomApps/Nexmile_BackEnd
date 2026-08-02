<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Address extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'label', 'contact_name', 'contact_phone',
        'line1', 'line2', 'landmark', 'city', 'state', 'pincode',
        'latitude', 'longitude', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Make this the user's default, clearing any previous one.
     *
     * Two defaults would make "deliver to my usual address" ambiguous, so the
     * demotion and promotion happen together.
     */
    public function makeDefault(): void
    {
        static::where('user_id', $this->user_id)
            ->whereKeyNot($this->getKey())
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }
}
