<?php

namespace App\Console\Commands;

use App\Enums\KycStatus;
use App\Models\Merchant;
use App\Services\Discovery\NearbyMerchantService;
use Illuminate\Console\Command;

/**
 * "Why can't customers see my restaurant?"
 *
 * The nearby search has several gates and failing any one of them produces the
 * same empty list, so the answer is never obvious from the outside. This walks
 * them in order and says which one is shut.
 */
class WhyHiddenCommand extends Command
{
    protected $signature = 'nexmile:why-hidden
                            {merchant : Merchant id, business name, or account email}
                            {--lat= : Customer latitude, to check the distance too}
                            {--lng= : Customer longitude}';

    protected $description = 'Explain why a restaurant is not showing in the nearby search';

    public function handle(NearbyMerchantService $nearby): int
    {
        $merchant = $this->resolve($this->argument('merchant'));

        if ($merchant === null) {
            $this->error('No merchant matches that id, name or email.');

            return self::FAILURE;
        }

        $this->line('');
        $this->line("  <options=bold>{$merchant->business_name}</>  (id {$merchant->id})");
        $this->line('');

        $blocking = 0;

        // 1. Coordinates. Without them the merchant is not even a candidate.
        $located = $merchant->latitude !== null && $merchant->longitude !== null;
        $this->check($located, 'Has coordinates',
            $located ? "{$merchant->latitude}, {$merchant->longitude}" : 'not set — add them under Details in the portal');
        $blocking += $located ? 0 : 1;

        // 2. KYC. The usual answer.
        $verified = $merchant->kyc_status === KycStatus::Verified;
        $this->check($verified, 'KYC verified',
            $verified ? 'verified' : ($merchant->kyc_status?->value ?? 'pending').' — an admin must verify it at /admin');
        $blocking += $verified ? 0 : 1;

        // 3. Distance, when a customer point was given.
        if ($located && $this->option('lat') !== null && $this->option('lng') !== null) {
            $lat = (float) $this->option('lat');
            $lng = (float) $this->option('lng');

            $distance = $nearby->distance($lat, $lng, (float) $merchant->latitude, (float) $merchant->longitude);
            $radius = $nearby->radiusFor($lat, $lng);
            $inRange = $distance <= $radius;

            $this->check($inRange, 'Within the delivery radius',
                round($distance).' m away, radius is '.$radius.' m'
                    .($inRange ? '' : ' — too far, and no setting fixes that'));
            $blocking += $inRange ? 0 : 1;
        }

        $this->line('');
        $this->line('  <fg=gray>The checks below do not hide the restaurant from the list.</>');
        $this->line('  <fg=gray>They decide whether it shows as open, and whether an order can be placed.</>');
        $this->line('');

        $this->check((bool) $merchant->is_accepting_orders, 'Switched on',
            $merchant->is_accepting_orders ? 'accepting orders' : 'switched off in the portal');

        $withinHours = $merchant->isWithinOperatingHours();
        $this->check($withinHours, 'Inside opening hours',
            $merchant->operatingHours()->exists()
                ? ($withinHours ? 'open now' : 'closed at this hour')
                : 'no hours set, treated as always open');

        /*
         * Worth naming where to fix it: FSSAI is admin-only, so a merchant
         * reading "no licence recorded" has nowhere in their own portal to go.
         */
        $this->check($merchant->hasValidFssai(), 'FSSAI licence current',
            $merchant->fssai_expiry_date
                ? ($merchant->hasValidFssai()
                    ? 'expires '.$merchant->fssai_expiry_date->toDateString()
                    : 'expired '.$merchant->fssai_expiry_date->toDateString().' — record a current one at /admin')
                : 'not recorded — an admin adds it at /admin from the uploaded certificate');

        $available = $merchant->menuItems()->where('is_available', true)->count();
        $this->check($available > 0, 'Has dishes on the menu',
            $available > 0 ? "{$available} available" : 'nothing available to order');

        $this->line('');

        if ($blocking > 0) {
            $this->line("  <fg=red;options=bold>Not in the nearby list.</> Fix the {$blocking} failing check above.");
        } elseif (! $merchant->isOpenNow()) {
            $this->line('  <fg=yellow;options=bold>In the list, but shown as closed.</>');

            /*
             * These two are chained and the order matters: a merchant cannot
             * switch themselves on until the licence is recorded, so telling
             * them to flip the switch first sends them round a loop.
             */
            if (! $merchant->hasValidFssai()) {
                $this->line('  <fg=gray>Record the FSSAI licence first — the merchant cannot switch on without it.</>');
            } elseif (! $merchant->is_accepting_orders) {
                $this->line('  <fg=gray>The merchant switches themselves on from their dashboard.</>');
            }
        } else {
            $this->line('  <fg=green;options=bold>Visible and open.</>');
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function check(bool $ok, string $label, string $detail): void
    {
        $mark = $ok ? '<fg=green>✔</>' : '<fg=red>✘</>';
        $this->line(sprintf('  %s  %-30s <fg=gray>%s</>', $mark, $label, $detail));
    }

    private function resolve(string $term): ?Merchant
    {
        if (ctype_digit($term)) {
            return Merchant::find((int) $term);
        }

        return Merchant::where('business_name', 'like', "%{$term}%")
            ->orWhereHas('user', fn ($q) => $q->where('email', $term))
            ->first();
    }
}
