<?php

namespace App\Console\Commands;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How much customer money we are holding, and what moved.
 *
 * Wallet balances are not revenue. They are money customers have handed over
 * and not yet spent — a liability, owed back as food — which is why it is held
 * in its own bank account rather than mixed with trading income.
 *
 * That account only means anything if somebody can say what should be in it.
 * This is that figure, plus the movement behind it, in the shape a bank
 * statement is reconciled against: what we held at the start, what came in,
 * what was spent, what we hold now.
 *
 * Opening plus movement must equal closing. If it does not, the ledger has a
 * gap in it, and the number in the bank is the only one anybody will trust.
 */
class WalletFloatCommand extends Command
{
    protected $signature = 'nexmile:wallet-float
                            {--from= : Start of the period, defaults to the start of this month}
                            {--to= : End of the period, defaults to today}';

    protected $description = 'Customer money held, and the movement behind it';

    public function handle(): int
    {
        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))->startOfDay()
            : now()->startOfMonth();

        $to = $this->option('to')
            ? Carbon::parse($this->option('to'))->endOfDay()
            : now()->endOfDay();

        if ($from->gt($to)) {
            $this->error('  The period starts after it ends.');

            return self::FAILURE;
        }

        $opening = $this->signedTotal(fn ($q) => $q->where('created_at', '<', $from));
        $movement = $this->movement($from, $to);

        $net = round(
            $movement['topped_up'] + $movement['refunded'] - $movement['spent'],
            2,
        );

        $closing = round($opening + $net, 2);

        $this->line('');
        $this->line("  <options=bold>{$from->format('j M Y')} – {$to->format('j M Y')}</>");
        $this->line('');

        $this->table(['', 'Amount'], [
            ['Held at the start', $this->money($opening)],
            ['Topped up', '+ '.$this->money($movement['topped_up'])],
            ['Refunded to wallets', '+ '.$this->money($movement['refunded'])],
            ['Spent on orders', '− '.$this->money($movement['spent'])],
            ['Adjustments', $this->money($movement['adjusted'])],
            ['<options=bold>Held at the end</>', '<options=bold>'.$this->money($closing).'</>'],
        ]);

        $this->line('');
        $this->line('  <options=bold>'.$this->money($closing).'</> is owed to customers'
            .($movement['customers'] > 0 ? ' across '.$movement['customers'].' wallet(s)' : ''));
        $this->line('  It is not revenue. It should be sitting in the wallet account.');
        $this->line('');

        return $this->crossCheck($to, $closing);
    }

    /**
     * Does the computed closing agree with the balances as they stand?
     *
     * Only checkable when the period runs to now — asked about last month, the
     * current balances have moved on since and disagreeing proves nothing.
     */
    protected function crossCheck(Carbon $to, float $closing): int
    {
        if ($to->lt(now()->startOfDay())) {
            $this->comment('  Historic period, so this is not cross-checked against');
            $this->comment('  today\'s balances. Run nexmile:check-wallets for that.');
            $this->line('');

            return self::SUCCESS;
        }

        $live = round((float) Wallet::sum('balance'), 2);

        if ($this->same($live, $closing)) {
            $this->info('  Agrees with the sum of every wallet balance.');
            $this->line('');

            return self::SUCCESS;
        }

        $this->error('  The ledger and the balances disagree.');
        $this->line('');
        $this->line('    Ledger says:   '.$this->money($closing));
        $this->line('    Balances say:  '.$this->money($live));
        $this->line('    Difference:    '.$this->money(round($live - $closing, 2)));
        $this->line('');
        $this->line('  Do not reconcile the bank against either figure until this is');
        $this->line('  resolved. nexmile:check-wallets names the wallets involved.');
        $this->line('');

        return self::FAILURE;
    }

    /**
     * What moved in the window, split the way the money actually moved.
     *
     * One grouped query rather than four. This is a report somebody runs at
     * month end over every transaction on the platform.
     *
     * @return array{topped_up: float, spent: float, refunded: float, adjusted: float, customers: int}
     */
    protected function movement(Carbon $from, Carbon $to): array
    {
        $rows = WalletTransaction::query()
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('type', 'direction')
            ->selectRaw('type, direction, SUM(amount) as total')
            ->get();

        $sum = fn (string $type, string $direction) => round((float) $rows
            ->where('type', $type)
            ->where('direction', $direction)
            ->sum('total'), 2);

        /*
         * An adjustment can go either way — ops putting something right —
         * so it is netted rather than assumed to be a credit.
         */
        $adjusted = round(
            $sum(WalletTransaction::ADJUSTMENT, WalletTransaction::CREDIT)
            - $sum(WalletTransaction::ADJUSTMENT, WalletTransaction::DEBIT),
            2,
        );

        return [
            'topped_up' => $sum(WalletTransaction::TOPUP, WalletTransaction::CREDIT),
            'spent' => $sum(WalletTransaction::ORDER_PAYMENT, WalletTransaction::DEBIT),
            'refunded' => $sum(WalletTransaction::REFUND, WalletTransaction::CREDIT),
            'adjusted' => $adjusted,
            'customers' => Wallet::where('balance', '>', 0)->count(),
        ];
    }

    /** Every movement ever, signed, optionally narrowed. */
    protected function signedTotal(?callable $narrow = null): float
    {
        $query = DB::table('wallet_transactions')
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) as total");

        if ($narrow !== null) {
            $narrow($query);
        }

        return round((float) $query->value('total'), 2);
    }

    /**
     * Two decimals of rupees cannot be compared with == in binary floating
     * point and be trusted.
     */
    protected function same(float $a, float $b): bool
    {
        return number_format($a, 2, '.', '') === number_format($b, 2, '.', '');
    }

    protected function money(float $amount): string
    {
        return '₹'.number_format($amount, 2);
    }
}
