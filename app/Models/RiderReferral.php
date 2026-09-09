<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One rider's invitation to someone they know. */
class RiderReferral extends Model
{
    /** Invited, and nobody has signed up on that number yet. */
    public const INVITED = 'invited';

    /** They signed up, but have not passed KYC. */
    public const JOINED = 'joined';

    /** Cleared to work, no deliveries yet. */
    public const ONBOARDED = 'onboarded';

    /** Delivering. This is the only state that earns anything. */
    public const WORKING = 'working';

    /** Nobody took it up in time, and the number is free again. */
    public const EXPIRED = 'expired';

    protected $fillable = [
        'referrer_rider_id', 'referred_phone', 'referred_name',
        'referred_city', 'invited_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Rider::class, 'referrer_rider_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(Rider::class, 'referred_rider_id');
    }

    public function bonuses(): HasMany
    {
        return $this->hasMany(RiderReferralBonus::class);
    }

    /** Still open, and still able to match somebody signing up. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('referred_rider_id')->where('expires_at', '>', now());
    }

    /**
     * Where this invitation has got to.
     *
     * Read from the friend's own rider record rather than from columns kept
     * up to date here. A second copy of "has this person passed KYC" is a
     * second copy that can be wrong, and this one would be wrong in the
     * direction of paying somebody.
     */
    public function state(): string
    {
        if ($this->referred_rider_id === null) {
            return $this->expires_at->isPast() ? self::EXPIRED : self::INVITED;
        }

        $rider = $this->referred;

        return match (true) {
            $rider === null => self::EXPIRED,
            $rider->completed_deliveries > 0 => self::WORKING,
            $rider->isKycVerified() => self::ONBOARDED,
            default => self::JOINED,
        };
    }

    /**
     * The friend's phone, most of it hidden.
     *
     * The referrer typed this number so they know it already, but their own
     * screen is not the place to print somebody else's mobile in full — a
     * shared or shoulder-surfed phone makes it everyone's.
     */
    public function maskedPhone(): string
    {
        return substr($this->referred_phone, 0, 2).'••••••'.substr($this->referred_phone, -2);
    }
}
