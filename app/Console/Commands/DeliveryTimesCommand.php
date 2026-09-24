<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * "Do we actually deliver within fifteen minutes of pickup?"
 *
 * The promise is measured from the rider collecting the food, not from the
 * customer tapping Order — the kitchen's time is the kitchen's. Both
 * timestamps have always been stored, so this is a question with an answer
 * rather than an argument.
 *
 * Reports the median and the ninetieth percentile, because the median is what
 * marketing would like to quote and the ninetieth is what decides whether the
 * promise survives a Friday. A median of nine minutes and a p90 of twenty-six
 * is a promise broken for one order in ten, which is enough to be the thing
 * the town says about you.
 */
class DeliveryTimesCommand extends Command
{
    protected $signature = 'nexmile:delivery-times
                            {--days=7 : How far back to look}
                            {--target=15 : The promise, in minutes}';

    protected $description = 'Measure pickup-to-doorstep time against the delivery promise';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $target = max(1, (int) $this->option('target'));

        $orders = Order::query()
            ->where('status', OrderStatus::Delivered->value)
            ->whereNotNull('picked_up_at')
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '>=', now()->subDays($days))
            ->get(['id', 'order_number', 'picked_up_at', 'delivered_at', 'last_mile_metres']);

        $this->line('');
        $this->line("  <options=bold>Pickup to doorstep</> — last {$days} days, promise {$target} min");
        $this->line('');

        if ($orders->isEmpty()) {
            $this->warn('  No delivered orders with both timestamps in that window.');
            $this->line('');
            $this->line('  Nothing to measure yet. Until there is, the promise is');
            $this->line('  arithmetic rather than evidence.');
            $this->line('');

            return self::SUCCESS;
        }

        // Seconds, not minutes: rounding each order first would quietly move
        // the median by half a minute on a sample this small.
        $seconds = $orders
            ->map(fn (Order $o) => $o->delivered_at->getTimestamp() - $o->picked_up_at->getTimestamp())
            ->filter(fn (int $s) => $s >= 0)
            ->sort()
            ->values();

        $within = $seconds->filter(fn (int $s) => $s <= $target * 60)->count();
        $total = $seconds->count();

        $this->line('  Orders measured:   <options=bold>'.$total.'</>');
        $this->line('  Median:            <options=bold>'.$this->minutes($this->percentile($seconds, 0.5)).'</>');
        $this->line('  90th percentile:   <options=bold>'.$this->minutes($this->percentile($seconds, 0.9)).'</>');
        $this->line('  Slowest:           <options=bold>'.$this->minutes($seconds->last()).'</>');
        $this->line('');

        $share = $within / $total * 100;
        $colour = $share >= 90 ? 'green' : ($share >= 75 ? 'yellow' : 'red');

        // number_format, so 90 prints as 90.0 rather than 90 — a share that
        // changes shape depending on the value is one nobody can grep for.
        $this->line(sprintf(
            '  Within %d min:    <fg=%s;options=bold>%s%%</>  (%d of %d)',
            $target, $colour, number_format($share, 1), $within, $total,
        ));

        /*
         * By how far the road actually runs, not how far it looks. A kilometre
         * around a level crossing is a different job from a kilometre down one
         * straight road, and a promise that ignores the difference gets broken
         * in the same few streets every week.
         */
        $measured = $orders->filter(fn (Order $o) => $o->last_mile_metres !== null);

        if ($measured->isNotEmpty()) {
            $this->line('');
            $this->line('  <options=bold>By distance</>');

            foreach ([[0, 500], [500, 1000], [1000, 1500], [1500, PHP_INT_MAX]] as [$from, $to]) {
                $band = $measured->filter(fn (Order $o) => $o->last_mile_metres >= $from && $o->last_mile_metres < $to);

                if ($band->isEmpty()) {
                    continue;
                }

                $times = $band->map(fn (Order $o) => $o->delivered_at->getTimestamp() - $o->picked_up_at->getTimestamp())
                    ->sort()->values();

                $label = $to === PHP_INT_MAX ? '1500 m+' : "{$from}–{$to} m";

                $this->line(sprintf(
                    '    %-12s %3d orders   median %s   p90 %s',
                    $label,
                    $band->count(),
                    $this->minutes($this->percentile($times, 0.5)),
                    $this->minutes($this->percentile($times, 0.9)),
                ));
            }
        }

        $this->line('');

        // The ones to look at. A promise is kept or broken one order at a
        // time, and the breaches are where the reason lives.
        $breached = $orders
            ->filter(fn (Order $o) => ($o->delivered_at->getTimestamp() - $o->picked_up_at->getTimestamp()) > $target * 60)
            ->sortByDesc(fn (Order $o) => $o->delivered_at->getTimestamp() - $o->picked_up_at->getTimestamp())
            ->take(5);

        if ($breached->isNotEmpty()) {
            $this->line('  <options=bold>Slowest breaches</>');

            foreach ($breached as $order) {
                $took = $order->delivered_at->getTimestamp() - $order->picked_up_at->getTimestamp();

                $this->line(sprintf(
                    '    #%-12s %s   %s',
                    $order->order_number,
                    $this->minutes($took),
                    $order->last_mile_metres === null ? 'distance unknown' : $order->last_mile_metres.' m',
                ));
            }

            $this->line('');
        }

        return self::SUCCESS;
    }

    /** @param Collection<int, int> $sorted */
    private function percentile($sorted, float $p): int
    {
        if ($sorted->isEmpty()) {
            return 0;
        }

        // Nearest rank. Exact enough for an operational figure, and it always
        // returns a time some real order actually took.
        $index = (int) ceil($p * $sorted->count()) - 1;

        return (int) $sorted[max(0, min($index, $sorted->count() - 1))];
    }

    private function minutes(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
