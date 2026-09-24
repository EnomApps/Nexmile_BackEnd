<?php

namespace App\Services\Riders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Rider;
use App\Models\RiderPayout;
use App\Models\RiderReferralBonus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Adding up a week's work and deciding what leaves the bank.
 *
 * Every figure is written once. A payout recomputed later from current rates
 * would quietly restate what somebody was already paid, and a rider who cannot
 * check last week's statement against last week's numbers has no reason to
 * believe this week's.
 *
 * This calculates and records. It does not move money — that is a bank
 * integration and a person pressing a button, and the two are kept apart
 * deliberately: a bug in the arithmetic should produce a wrong statement
 * somebody can look at, never a wrong transfer.
 */
class PayoutRunService
{
    public function __construct(protected TdsCalculator $tds) {}

    /**
     * Build every rider's payout for the week containing this date.
     *
     * Safe to run twice. The week is keyed per rider, so a cron that fires
     * again or an admin checking it worked updates the row rather than paying
     * anybody a second time — and a week already paid is left alone entirely.
     *
     * @return Collection<int, RiderPayout>
     */
    public function run(Carbon $anyDayInWeek): Collection
    {
        [$start, $end] = $this->week($anyDayInWeek);

        $earned = $this->deliveryEarnings($start, $end);
        $bonuses = $this->referralEarnings($start, $end);

        $riderIds = $earned->keys()->merge($bonuses->keys())->unique();

        return Rider::query()
            ->whereIn('id', $riderIds)
            ->get()
            ->map(fn (Rider $rider) => $this->buildOne(
                $rider,
                $start,
                $end,
                (float) ($earned[$rider->id] ?? 0),
                (float) ($bonuses[$rider->id] ?? 0),
            ))
            ->filter()
            ->values();
    }

    /**
     * One rider's week.
     *
     * Anything carried from an earlier week is added here rather than left
     * behind: a rider who earned ₹40 three weeks running is owed ₹120, not
     * three rows nobody ever pays.
     */
    protected function buildOne(Rider $rider, Carbon $start, Carbon $end, float $delivery, float $referral): ?RiderPayout
    {
        $existing = RiderPayout::where('rider_id', $rider->id)
            ->whereDate('period_start', $start)
            ->first();

        // Already sent. Nothing about a paid week is ours to change.
        if ($existing?->status === RiderPayout::PAID) {
            return $existing;
        }

        /*
         * Decided once, when this week's row is first built, and kept.
         *
         * The carried rows are marked as they are consumed, so asking again on
         * a second run finds nothing and would rebuild the total without them
         * — the rider losing exactly the amount that was too small to send in
         * the first place, which is also too small for anyone to spot.
         */
        $carried = $existing !== null
            ? (float) $existing->carried_in
            : $this->carriedForward($rider, $start);

        $gross = round($delivery + $referral + $carried, 2);

        if ($gross <= 0) {
            return null;
        }

        $tds = $this->tds->on($rider, $gross, $end);
        $net = round($gross - $tds['amount'], 2);

        /*
         * Too small to be worth a bank transfer. Rolled into next week rather
         * than sent — the fees and the reconciliation cost more than the money
         * moves, and a rider would rather have it added than see a ₹40 line.
         */
        $status = $net < (float) config('payouts.minimum_transfer')
            ? RiderPayout::CARRIED
            : RiderPayout::PENDING;

        $figures = [
            'rider_id' => $rider->id,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'delivery_earnings' => $delivery,
            'referral_earnings' => $referral,
            'carried_in' => $carried,
            'gross' => $gross,
            'tds' => $tds['amount'],
            'tds_rate' => $tds['rate'],
            'tds_year_to_date' => $tds['year_to_date'],
            'net' => $net,
            'status' => $status,
        ];

        /*
         * Updating the row already found rather than matching on the dates
         * again. `period_start` is a date column holding a datetime, so an
         * updateOrCreate keyed on '2026-09-14' never matches '2026-09-14
         * 00:00:00' — it inserts instead, and the unique index is the only
         * thing standing between that and paying a week twice.
         */
        return DB::transaction(function () use ($existing, $figures) {
            if ($existing !== null) {
                $existing->forceFill($figures)->save();

                return $existing;
            }

            return RiderPayout::create($figures);
        });
    }

    /**
     * Weeks that were too small to send, now folded into this one.
     *
     * Marked as they are consumed, so the same ₹40 cannot be carried into two
     * different weeks by a re-run.
     */
    protected function carriedForward(Rider $rider, Carbon $start): float
    {
        $rows = RiderPayout::where('rider_id', $rider->id)
            ->where('status', RiderPayout::CARRIED)
            ->whereDate('period_start', '<', $start)
            ->get();

        if ($rows->isEmpty()) {
            return 0.0;
        }

        $total = round((float) $rows->sum('net'), 2);

        RiderPayout::whereIn('id', $rows->pluck('id'))->update([
            'status' => RiderPayout::PAID,
            'note' => 'Carried into the week beginning '.$start->toDateString(),
            'paid_at' => now(),
        ]);

        return $total;
    }

    /**
     * What each rider earned delivering in the window.
     *
     * Keyed on delivery, not on when the order was placed — a Sunday-night
     * order delivered after midnight belongs to the week the rider worked it.
     *
     * @return Collection<int, float>
     */
    protected function deliveryEarnings(Carbon $start, Carbon $end): Collection
    {
        return Order::query()
            ->where('status', OrderStatus::Delivered->value)
            ->whereNotNull('rider_id')
            ->whereNotNull('rider_payout')
            ->whereBetween('delivered_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->groupBy('rider_id')
            ->selectRaw('rider_id, SUM(rider_payout) as total')
            ->pluck('total', 'rider_id')
            ->map(fn ($v) => round((float) $v, 2));
    }

    /** @return Collection<int, float> */
    protected function referralEarnings(Carbon $start, Carbon $end): Collection
    {
        return RiderReferralBonus::query()
            ->whereBetween('earned_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->groupBy('rider_id')
            ->selectRaw('rider_id, SUM(amount) as total')
            ->pluck('total', 'rider_id')
            ->map(fn ($v) => round((float) $v, 2));
    }

    /**
     * Monday to Sunday containing the given day.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function week(Carbon $day): array
    {
        $start = $day->copy()->startOfWeek(Carbon::MONDAY);

        return [$start, $start->copy()->addDays((int) config('payouts.period_days') - 1)];
    }
}
