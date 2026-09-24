<?php

namespace App\Console\Commands;

use App\Models\RiderPayout;
use App\Services\Riders\PayoutRunService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Work out what every rider is owed for a week.
 *
 * Calculates and records. It does not move money — that is a bank integration
 * and a person pressing a button, kept apart from this on purpose: an error in
 * the arithmetic should produce a statement somebody can look at, never a
 * wrong transfer.
 */
class RunPayoutsCommand extends Command
{
    protected $signature = 'nexmile:run-payouts
                            {--week= : Any date in the week to pay, defaults to last week}';

    protected $description = 'Calculate rider payouts for a week';

    public function handle(PayoutRunService $payouts): int
    {
        $day = $this->option('week')
            ? Carbon::parse($this->option('week'))
            // Last week by default. Running it for the week in progress would
            // pay a rider on Wednesday for a Saturday they have not worked.
            : now()->subWeek();

        [$start, $end] = $payouts->week($day);

        $this->line('');
        $this->line("  <options=bold>{$start->format('j M')} – {$end->format('j M Y')}</>");
        $this->line('');

        $rows = $payouts->run($day);

        if ($rows->isEmpty()) {
            $this->warn('  Nobody earned anything that week.');
            $this->line('');

            return self::SUCCESS;
        }

        $payable = $rows->where('status', RiderPayout::PENDING);
        $carried = $rows->where('status', RiderPayout::CARRIED);

        foreach ($rows->sortByDesc('net') as $payout) {
            $this->line(sprintf(
                '    %-22s  %10s  %9s  %10s   %s',
                mb_strimwidth((string) $payout->rider?->full_name, 0, 22, '…'),
                '₹'.number_format((float) $payout->gross, 2),
                (float) $payout->tds > 0 ? '−₹'.number_format((float) $payout->tds, 2) : '—',
                '₹'.number_format((float) $payout->net, 2),
                $payout->status,
            ));
        }

        $this->line('');
        $this->line('  Riders:        <options=bold>'.$rows->count().'</>');
        $this->line('  Gross:         <options=bold>₹'.number_format((float) $rows->sum('gross'), 2).'</>');
        $this->line('  TDS withheld:  <options=bold>₹'.number_format((float) $rows->sum('tds'), 2).'</>');
        $this->line('  To transfer:   <options=bold>₹'.number_format((float) $payable->sum('net'), 2).'</> to '.$payable->count());

        if ($carried->isNotEmpty()) {
            // Not a problem, but worth saying out loud so nobody hunts for the
            // difference between the gross and what actually goes out.
            $this->line('  Carried:       ₹'.number_format((float) $carried->sum('net'), 2)
                .' for '.$carried->count().' under the minimum');
        }

        if (! config('payouts.tds.enabled')) {
            $this->line('');
            $this->warn('  TDS is switched off, so nothing was withheld.');
            $this->line('  Turn it on only once the accountant has confirmed the');
            $this->line('  treatment and there is a process to remit what is taken.');
        }

        $this->line('');

        return self::SUCCESS;
    }
}
