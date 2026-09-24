<?php

namespace App\Services\Riders;

use App\Models\Rider;
use App\Models\RiderPayout;
use Illuminate\Support\Carbon;

/**
 * Tax deducted at source on a rider's pay.
 *
 * Withheld by the platform and paid to the government against the rider's PAN.
 * It is not money Nexmile keeps, and the rider gets it back when they file —
 * which is why the statement says so rather than showing a bare subtraction.
 *
 * **The deduction is only half the obligation.** Money withheld has to be
 * remitted, quarterly returns filed and a certificate issued, and none of that
 * happens here. Withholding and not remitting is an offence, so this stays off
 * until somebody has confirmed the whole process exists.
 */
class TdsCalculator
{
    /**
     * What to withhold from this week, given what the year has already paid.
     *
     * @return array{amount: float, rate: float|null, year_to_date: float}
     */
    public function on(Rider $rider, float $gross, ?Carbon $at = null): array
    {
        $at ??= now();
        $paidThisYear = $this->paidInFinancialYear($rider, $at);

        $nothing = ['amount' => 0.0, 'rate' => null, 'year_to_date' => $paidThisYear];

        if (! config('payouts.tds.enabled') || $gross <= 0) {
            return $nothing;
        }

        /*
         * Nothing is withheld until the year's total crosses the threshold, or
         * this single payment is large enough on its own. Deducting from rupee
         * one would take money from riders who never owe it and leave the
         * platform explaining why.
         *
         * Measured on the total *including* this week: the week that crosses
         * the line is the week deduction starts, not the one after.
         */
        $crossesAnnual = ($paidThisYear + $gross) > (float) config('payouts.tds.annual_threshold');
        $largeSingle = $gross >= (float) config('payouts.tds.single_payment_threshold');

        if (! $crossesAnnual && ! $largeSingle) {
            return $nothing;
        }

        $rate = $this->rateFor($rider);

        return [
            // Rounded down to the paisa. Over-withholding is the platform
            // taking money it was not asked to take.
            'amount' => floor($gross * $rate) / 100,
            'rate' => $rate,
            'year_to_date' => $paidThisYear,
        ];
    }

    /**
     * 1% with a PAN on file, 20% without.
     *
     * The penalty rate is section 206AA and is not a choice — deducting 1%
     * from someone with no PAN leaves the platform owing the difference. The
     * real answer is to collect the PAN, which onboarding already asks for.
     */
    public function rateFor(Rider $rider): float
    {
        return $this->hasPan($rider)
            ? (float) config('payouts.tds.rate')
            : (float) config('payouts.tds.rate_without_pan');
    }

    /** A PAN is ten characters in a fixed shape; anything else is not one. */
    public function hasPan(Rider $rider): bool
    {
        return (bool) preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', strtoupper((string) $rider->pan));
    }

    /**
     * Gross already paid to this rider since the financial year began.
     *
     * India's year starts in April. Reading it as January would reset every
     * rider's running total nine months early and under-deduct for the rest of
     * the year — the sort of error that surfaces as a demand notice long after
     * anybody remembers writing the code.
     */
    public function paidInFinancialYear(Rider $rider, ?Carbon $at = null): float
    {
        return round((float) RiderPayout::query()
            ->where('rider_id', $rider->id)
            ->where('period_start', '>=', $this->financialYearStart($at ?? now()))
            ->whereIn('status', [RiderPayout::PAID, RiderPayout::PENDING])
            ->sum('gross'), 2);
    }

    public function financialYearStart(Carbon $at): Carbon
    {
        $month = (int) config('payouts.tds.financial_year_starts_month');

        $start = $at->copy()->startOfDay()->setMonth($month)->setDay(1);

        // Before April, the year in progress began last April.
        return $start->isAfter($at) ? $start->subYear() : $start;
    }
}
