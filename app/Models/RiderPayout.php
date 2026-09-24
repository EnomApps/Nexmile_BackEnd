<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One week's pay for one rider. Written once, never recomputed. */
class RiderPayout extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    /** Under the minimum, so it rolls into the next week rather than moving. */
    public const CARRIED = 'carried';

    public const FAILED = 'failed';

    protected $fillable = [
        'rider_id', 'period_start', 'period_end',
        'delivery_earnings', 'referral_earnings', 'carried_in', 'gross',
        'tds', 'tds_rate', 'tds_year_to_date', 'net',
        'status', 'paid_at', 'reference', 'note',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'delivery_earnings' => 'decimal:2',
            'referral_earnings' => 'decimal:2',
            'carried_in' => 'decimal:2',
            'gross' => 'decimal:2',
            'tds' => 'decimal:2',
            'tds_rate' => 'decimal:2',
            'tds_year_to_date' => 'decimal:2',
            'net' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    /** Money that has actually left the bank. */
    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', self::PAID);
    }

    /** Owed and not yet sent — what a transfer run works through. */
    public function scopePayable(Builder $query): Builder
    {
        return $query->whereIn('status', [self::PENDING, self::FAILED])
            ->where('net', '>', 0);
    }

    /**
     * The deductions screen, in the rider's words rather than ours.
     *
     * Empty when nothing was withheld, which is the normal case — a rider
     * shown a "Deductions" heading with nothing under it reasonably wonders
     * what was taken.
     *
     * @return list<array{label: string, amount: float, note: string}>
     */
    public function deductions(): array
    {
        if ((float) $this->tds <= 0) {
            return [];
        }

        return [[
            'label' => __('portal.payouts.tds', ['rate' => rtrim(rtrim((string) $this->tds_rate, '0'), '.')]),
            'amount' => (float) $this->tds,
            /*
             * The part that matters to the rider. This is not money Nexmile
             * kept — it has gone to the government against their PAN, and they
             * get it back when they file. Saying so is the difference between
             * a deduction and a grievance.
             */
            'note' => __('portal.payouts.tds_note'),
        ]];
    }
}
