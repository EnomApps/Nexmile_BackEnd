<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money a referrer has earned. Written once and never revised.
 *
 * The threshold and the amount are copied in rather than read from config,
 * so changing the scheme next quarter cannot restate what somebody was paid
 * last quarter.
 */
class RiderReferralBonus extends Model
{
    protected $fillable = [
        'rider_referral_id', 'rider_id', 'deliveries_required', 'amount', 'earned_at',
    ];

    protected function casts(): array
    {
        return [
            'deliveries_required' => 'integer',
            'amount' => 'decimal:2',
            'earned_at' => 'datetime',
        ];
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(RiderReferral::class, 'rider_referral_id');
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }
}
