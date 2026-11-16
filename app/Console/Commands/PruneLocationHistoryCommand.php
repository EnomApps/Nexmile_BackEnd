<?php

namespace App\Console\Commands;

use App\Models\RiderLocationPing;
use Illuminate\Console\Command;

/**
 * Delete rider trails older than the stated retention period.
 *
 * This is the half of location history that makes the other half allowable.
 * A trail is personal data about somebody's working day, and keeping it
 * because deleting it was never scheduled is not a decision anybody made —
 * it is the absence of one. `tracking.history.retention_days` is the period
 * Nexmile commits to, and this command is what makes that true rather than
 * aspirational.
 *
 * Deletes in chunks. A single DELETE across a month of pings holds a lock
 * long enough to be felt by riders pinging into the same table, and this runs
 * at night precisely so that nobody notices it — which a table-long lock
 * would undo.
 */
class PruneLocationHistoryCommand extends Command
{
    protected $signature = 'nexmile:prune-location-history
                            {--days= : Override the configured retention period}
                            {--dry-run : Count what would go, delete nothing}';

    protected $description = 'Delete rider location trails past their retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('tracking.history.retention_days'));

        /*
         * Refused rather than treated as "keep nothing". A typo in a cron
         * entry should not be the thing that wipes every trail on the
         * platform, and there is no legitimate reading of zero days that
         * this command is the right place to act on.
         */
        if ($days < 1) {
            $this->error('Retention must be at least one day. Got: '.$days);

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days)->startOfDay();

        $this->line('');
        $this->line("  Deleting pings recorded before <options=bold>{$cutoff->toDateTimeString()}</> ({$days} days).");

        $total = RiderLocationPing::where('recorded_at', '<', $cutoff)->count();

        if ($total === 0) {
            $this->line('  <fg=green>Nothing to delete.</>');
            $this->line('');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line("  <fg=yellow>{$total} ping(s) would be deleted.</> Nothing was changed.");
            $this->line('');

            return self::SUCCESS;
        }

        $deleted = 0;

        /*
         * Bounded by the count taken above rather than looping until the
         * query returns nothing: riders are still pinging while this runs,
         * and a loop with no ceiling would chase its own tail on a busy
         * evening and delete rows it was never asked about.
         */
        while ($deleted < $total) {
            $batch = RiderLocationPing::where('recorded_at', '<', $cutoff)
                ->limit(1000)
                ->delete();

            if ($batch === 0) {
                break;
            }

            $deleted += $batch;
        }

        $this->line("  <fg=green;options=bold>Deleted {$deleted} ping(s).</>");
        $this->line('');

        return self::SUCCESS;
    }
}
