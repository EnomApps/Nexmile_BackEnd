<?php

namespace App\Console\Commands;

use App\Models\Wallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Does every wallet balance match its own ledger?
 *
 * The balance is stored rather than summed on read, because a spend has to
 * lock something and a SUM cannot be locked cheaply. That choice is only safe
 * if somebody checks — a stored total can drift, and the way it would show up
 * otherwise is a customer insisting they had ₹200 and nothing in the logs to
 * say whether they did.
 *
 * Reports, never repairs. A wallet that disagrees with its history is a bug
 * somewhere upstream, and silently writing the sum over the balance would hide
 * the evidence while leaving the cause in place.
 */
class CheckWalletsCommand extends Command
{
    protected $signature = 'nexmile:check-wallets {--user= : Check one customer}';

    protected $description = 'Verify every wallet balance against its ledger';

    public function handle(): int
    {
        $query = Wallet::query()->with('user:id,name,phone');

        if ($this->option('user')) {
            $query->where('user_id', (int) $this->option('user'));
        }

        $wallets = $query->get();

        if ($wallets->isEmpty()) {
            $this->info('  No wallets to check.');

            return self::SUCCESS;
        }

        /*
         * One grouped query rather than one per wallet. This runs on a cron
         * over every customer on the platform, and a per-wallet SUM would be
         * one round trip each.
         */
        $ledger = DB::table('wallet_transactions')
            ->groupBy('wallet_id')
            ->selectRaw("wallet_id, SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) as total")
            ->pluck('total', 'wallet_id');

        $wrong = [];

        foreach ($wallets as $wallet) {
            $summed = round((float) ($ledger[$wallet->id] ?? 0), 2);
            $stored = round((float) $wallet->balance, 2);

            // Compared as strings. Two decimals of rupees cannot be compared
            // with == in binary floating point and be trusted.
            if (number_format($summed, 2, '.', '') === number_format($stored, 2, '.', '')) {
                continue;
            }

            $wrong[] = [
                $wallet->id,
                $wallet->user?->phone ?? '—',
                number_format($stored, 2),
                number_format($summed, 2),
                number_format($stored - $summed, 2),
            ];
        }

        $this->line('');

        if ($wrong === []) {
            $this->info(sprintf('  %d wallet(s) checked, every balance matches its ledger.', $wallets->count()));
            $this->line('');

            return self::SUCCESS;
        }

        $this->error(sprintf('  %d of %d wallet(s) disagree with their ledger.', count($wrong), $wallets->count()));
        $this->line('');
        $this->table(['Wallet', 'Customer', 'Balance', 'Ledger', 'Difference'], $wrong);

        $this->line('');
        $this->line('  Do not correct the balance by hand. Find what wrote it, fix');
        $this->line('  that, then put the difference right with an adjustment row so');
        $this->line('  the history still explains the total.');
        $this->line('');

        return self::FAILURE;
    }
}
