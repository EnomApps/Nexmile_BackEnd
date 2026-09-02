<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What Nexmile decided, as distinct from what a customer said. */
class RiderWarning extends Model
{
    protected $fillable = ['rider_id', 'rider_report_id', 'reason', 'issued_by_user_id'];

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(RiderReport::class, 'rider_report_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    /**
     * Warnings still counting against a rider.
     *
     * Ones that never expire mean a bad month follows someone forever, and a
     * rider with nothing to gain from improving is a rider who leaves.
     */
    public function scopeCounting(Builder $query): Builder
    {
        return $query->where('created_at', '>=', now()->subDays((int) config('conduct.warning_window_days')));
    }
}
