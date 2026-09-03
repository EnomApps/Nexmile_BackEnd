<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use Illuminate\Console\Command;

/**
 * Folds a due rate change into the rate itself.
 *
 * Housekeeping, not correctness. Orders are already priced from the dates by
 * {@see Merchant::effectiveCommissionRate()}, so a restaurant is charged the
 * right rate on the right morning whether or not this ever runs — which
 * matters, because the scheduler needs a crontab line that nobody has
 * confirmed is installed.
 *
 * What it buys is an admin page that tells the truth. Leaving "10%, changes to
 * 12% on 14 March" on screen in April invites somebody to set the rate to 12%
 * by hand, which is how a restaurant ends up on 12% twice.
 */
class ApplyScheduledCommissionCommand extends Command
{
    protected $signature = 'nexmile:apply-commission-changes';

    protected $description = 'Fold commission changes whose date has passed into the merchant rate';

    public function handle(): int
    {
        $due = Merchant::query()
            ->whereNotNull('commission_changes_on')
            ->whereNotNull('scheduled_commission_rate')
            ->whereDate('commission_changes_on', '<=', now())
            ->get();

        foreach ($due as $merchant) {
            $was = (float) $merchant->commission_rate;
            $now = (float) $merchant->scheduled_commission_rate;

            $merchant->forceFill([
                'commission_rate' => $now,
                'scheduled_commission_rate' => null,
                'commission_changes_on' => null,
            ])->save();

            $this->line("  {$merchant->business_name}: {$was}% → {$now}%");
        }

        $this->info($due->isEmpty()
            ? 'No commission changes were due.'
            : $due->count().' commission change(s) applied.');

        return self::SUCCESS;
    }
}
