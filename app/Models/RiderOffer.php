<?php

namespace App\Models;

use App\Enums\OfferStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ringing order card offered to one rider.
 *
 * A row exists for every offer ever made, accepted or not. That is the record
 * of how long dispatch took and who was asked — which is the only way to
 * answer "why did this delivery take forty minutes" after the fact.
 */
class RiderOffer extends Model
{
    use HasUlids;

    protected $fillable = [
        'ulid',
        'order_id',
        'rider_id',
        'status',
        'wave',
        'distance_metres',
        'offered_at',
        'expires_at',
        'responded_at',
        'reason',
    ];

    /**
     * The ULID is a second identifier, not the primary key.
     *
     * HasUlids would otherwise try to make `id` a ULID; the table keeps an
     * auto-incrementing `id` and carries the ULID beside it as the value the
     * app and the push payload see.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getIncrementing(): bool
    {
        return true;
    }

    public function getKeyType(): string
    {
        return 'int';
    }

    /**
     * Resolved by ULID in routes, so `/offers/{offer}/accept` takes the
     * identifier the app was actually given.
     */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OfferStatus::class,
            'wave' => 'integer',
            'distance_metres' => 'integer',
            'offered_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    /**
     * Still ringing: pending, and the window has not closed.
     *
     * Both halves matter. A pending row whose `expires_at` has passed is an
     * offer nothing got round to expiring yet — the queue was slow, the
     * worker restarted — and treating it as live would hold a rider out of
     * dispatch on the strength of a job that may never run.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', OfferStatus::Pending->value)
            ->where('expires_at', '>', now());
    }

    public function isLive(): bool
    {
        return $this->status === OfferStatus::Pending && $this->expires_at->isFuture();
    }

    /** Seconds the rider has left, floored at zero. */
    public function secondsLeft(): int
    {
        return max(0, now()->diffInSeconds($this->expires_at, false));
    }
}
