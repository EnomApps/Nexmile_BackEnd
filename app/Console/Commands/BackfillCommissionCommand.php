<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use Illuminate\Console\Command;

/**
 * Put existing merchants onto a commission rate.
 *
 * Registration now stamps the default, but every merchant created before that
 * is on 0% and earns the platform nothing. This is a one-off for those.
 */
class BackfillCommissionCommand extends Command
{
    protected $signature = 'nexmile:backfill-commission
                            {--rate= : Percentage to apply, defaults to config}
                            {--dry-run : Show what would change without writing}';

    protected $description = 'Set a commission rate on merchants currently on 0%';

    public function handle(): int
    {
        $rate = (float) ($this->option('rate') ?? config('checkout.default_commission_rate'));
        $max = (float) config('checkout.max_commission_rate');

        if ($rate <= 0 || $rate > $max) {
            $this->error("Rate must be above 0 and at most {$max}%.");

            return self::FAILURE;
        }

        // Only merchants nobody has deliberately set. A negotiated 0% would be
        // indistinguishable, but that is a contract someone would have to have
        // entered by hand, and none exists yet.
        $merchants = Merchant::where('commission_rate', 0)->get();

        if ($merchants->isEmpty()) {
            $this->info('Every merchant already has a commission rate.');

            return self::SUCCESS;
        }

        $this->table(
            ['Id', 'Business', 'Current', 'New'],
            $merchants->map(fn (Merchant $m) => [$m->id, $m->business_name, '0%', $rate.'%'])->all(),
        );

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing written.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Set {$merchants->count()} merchant(s) to {$rate}%?", false)) {
            return self::FAILURE;
        }

        foreach ($merchants as $merchant) {
            // forceFill: not mass-assignable, by design.
            $merchant->forceFill(['commission_rate' => $rate])->save();
        }

        $this->info("Updated {$merchants->count()} merchant(s). Existing orders are unchanged.");

        return self::SUCCESS;
    }
}
