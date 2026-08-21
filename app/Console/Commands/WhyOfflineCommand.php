<?php

namespace App\Console\Commands;

use App\Enums\KycStatus;
use App\Enums\RiderStatus;
use App\Models\Rider;
use Illuminate\Console\Command;

/**
 * "I verified this rider, so why can't they go online?"
 *
 * Three separate gates produce the same refusal in the app, and two of them
 * are invisible from the admin panel — an admin who has just pressed Verify
 * has no way to see that the licence expiry was never filled in. This says
 * which gate is shut.
 */
class WhyOfflineCommand extends Command
{
    protected $signature = 'nexmile:why-offline
                            {rider : Rider id, name, phone, or account email}';

    protected $description = 'Explain why a rider cannot go online';

    public function handle(): int
    {
        $rider = $this->resolve($this->argument('rider'));

        if ($rider === null) {
            $this->error('No rider matches that id, name, phone or email.');

            return self::FAILURE;
        }

        $this->line('');
        $this->line("  <options=bold>{$rider->full_name}</>  (id {$rider->id})");
        $this->line('');

        $blocking = 0;

        // 1. KYC. What the admin panel controls, and usually the only one an
        // admin thinks about.
        $verified = $rider->kyc_status === KycStatus::Verified;
        $this->check($verified, 'KYC verified',
            $verified
                ? 'verified '.($rider->kyc_verified_at?->toDateString() ?? '')
                : ($rider->kyc_status?->value ?? 'pending').' — an admin verifies it at /admin');
        $blocking += $verified ? 0 : 1;

        /*
         * 2 and 3. The dates. A null expiry counts as expired, which is the
         * safe reading but a confusing one: pressing Verify does not set these,
         * so a fully approved rider can still be blocked by a blank field.
         */
        foreach ([
            'Driving licence in date' => $rider->driving_licence_expiry,
            'Insurance in date' => $rider->insurance_expiry,
        ] as $label => $expiry) {
            $ok = $expiry !== null && ! $expiry->isPast();

            $this->check($ok, $label, match (true) {
                $expiry === null => 'not recorded — counts as expired until the date is filled in',
                $expiry->isPast() => 'expired '.$expiry->toDateString(),
                default => 'expires '.$expiry->toDateString(),
            });

            $blocking += $ok ? 0 : 1;
        }

        $this->line('');

        if ($blocking > 0) {
            $this->line("  <fg=red;options=bold>Cannot go online.</> Fix the {$blocking} failing check above.");
            $this->line('');

            return self::SUCCESS;
        }

        $this->line('  <fg=green;options=bold>Eligible to go online.</>');
        $this->line('');

        /*
         * Past this point the backend is not refusing anything, so the answer
         * is on the app side — worth saying plainly, because the next question
         * is always "then why is it still showing the banner?".
         */
        $this->line('  <fg=gray>duty_status is currently <options=bold>'.($rider->duty_status?->value ?? 'unset').'</>.</>');

        if ($rider->duty_status !== RiderStatus::Available) {
            $this->line('  <fg=gray>That is expected while offline: the rider going on duty sets it.</>');
            $this->line('  <fg=gray>POST /v1/rider/duty-status {"duty_status":"available"} will now succeed.</>');
            $this->line('  <fg=gray>If the app still refuses, it is gating locally — check it reads</>');
            $this->line('  <fg=gray>can_go_online, not can_accept_orders.</>');
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function check(bool $ok, string $label, string $detail): void
    {
        $mark = $ok ? '<fg=green>✔</>' : '<fg=red>✘</>';
        $this->line(sprintf('  %s  %-28s <fg=gray>%s</>', $mark, $label, $detail));
    }

    private function resolve(string $term): ?Rider
    {
        if (ctype_digit($term) && strlen($term) < 10) {
            return Rider::find((int) $term);
        }

        return Rider::where('full_name', 'like', "%{$term}%")
            ->orWhereHas('user', fn ($q) => $q->where('email', $term)->orWhere('phone', $term))
            ->first();
    }
}
