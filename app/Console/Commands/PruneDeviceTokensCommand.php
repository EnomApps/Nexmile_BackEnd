<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use Illuminate\Console\Command;

/**
 * Forget phones that stopped answering.
 *
 * FCM tells us about a token the moment it is rejected, and those are deleted
 * on the spot. This catches the quieter case: an app uninstalled from a phone
 * that never opened again, whose token FCM will keep accepting for months.
 * Every one is a wasted HTTP request on every send, forever.
 */
class PruneDeviceTokensCommand extends Command
{
    protected $signature = 'nexmile:prune-devices {--days= : Override the configured age}';

    protected $description = 'Delete device tokens that have not been used in months';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('push.prune_after_days'));

        $deleted = DeviceToken::query()
            ->where(fn ($q) => $q
                ->where('last_used_at', '<', now()->subDays($days))
                // Never used at all, and registered long ago: permission was
                // granted and the app never opened again.
                ->orWhere(fn ($w) => $w->whereNull('last_used_at')
                    ->where('created_at', '<', now()->subDays($days))))
            ->delete();

        $this->line("  Pruned {$deleted} device tokens older than {$days} days.");

        return self::SUCCESS;
    }
}
