<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Push\PushService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Prove the Firebase credentials work, without waiting for a real order.
 *
 * The alternative is discovering the key is wrong when a rider misses their
 * first delivery offer — by which point the failure is buried in a queue
 * worker's log and looks like a dispatch problem rather than a config one.
 */
class TestPushCommand extends Command
{
    protected $signature = 'nexmile:test-push
                            {user : User id, phone, or email}
                            {--app=customer : customer, rider or merchant}
                            {--now : Send inline rather than through the queue}';

    protected $description = 'Send a test notification and report exactly what happened';

    public function handle(PushService $push): int
    {
        $this->line('');
        $this->line('  Driver: <options=bold>'.(config('push.driver') ?: 'log (nothing will actually send)').'</>');

        if (config('push.driver') === 'fcm') {
            $credentials = config('push.fcm.credentials');

            $this->check(
                config('push.fcm.project_id') !== null,
                'FCM_PROJECT_ID set',
                (string) config('push.fcm.project_id'),
            );

            $this->check(
                $credentials !== null && is_file($credentials),
                'Credentials file readable',
                $credentials === null
                    ? 'FCM_CREDENTIALS is not set'
                    : (is_file($credentials) ? $credentials : "not found at {$credentials}"),
            );

            /*
             * A world-readable service account key is worth flagging loudly:
             * it can notify every install of both apps, and anyone with shell
             * access could take it.
             */
            if ($credentials !== null && is_file($credentials)) {
                $mode = substr(sprintf('%o', fileperms($credentials)), -3);

                $this->check(
                    in_array($mode, ['600', '640', '440'], true),
                    'Credentials not world readable',
                    "mode {$mode}",
                );
            }
        }

        $user = $this->resolve((string) $this->argument('user'));

        if ($user === null) {
            $this->error('No user matches that id, phone or email.');

            return self::FAILURE;
        }

        $app = (string) $this->option('app');

        $devices = DeviceToken::where('user_id', $user->id)->where('app', $app)->count();

        $this->check(
            $devices > 0,
            "Registered {$app} devices",
            $devices > 0
                ? "{$devices} device(s)"
                : 'none — the app has not called POST /v1/devices for this account',
        );

        $this->line('');

        if ($devices === 0) {
            $this->line('  <fg=yellow>Nothing to send to.</> Sign in on the app first, and make sure it');
            $this->line('  <fg=yellow>registers its token after sign-in.</>');
            $this->line('');

            return self::SUCCESS;
        }

        $title = 'Nexmile test';
        $body = 'If you can read this, push notifications are working.';
        $data = ['type' => 'test'];

        try {
            if ($this->option('now')) {
                // Inline, so a broken key surfaces here rather than in a queue
                // worker's log where nobody is looking.
                $push->deliver([$user->id], $app, $title, $body, $data);
                $this->line('  <fg=green;options=bold>Sent.</> Check the device.');
            } else {
                $push->toUser($user, $app, $title, $body, $data);
                $this->line('  <fg=green;options=bold>Queued.</> It sends when a worker picks it up —');
                $this->line('  <fg=gray>if nothing arrives, check that queue:work is running.</>');
                $this->line('  <fg=gray>Use --now to send inline and see the error instead.</>');
            }
        } catch (Throwable $e) {
            $this->line('');
            $this->error('  Failed: '.$e->getMessage());
            $this->line('');

            return self::FAILURE;
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function check(bool $ok, string $label, string $detail): void
    {
        $mark = $ok ? '<fg=green>✔</>' : '<fg=red>✘</>';
        $this->line(sprintf('  %s  %-30s <fg=gray>%s</>', $mark, $label, $detail));
    }

    private function resolve(string $term): ?User
    {
        if (ctype_digit($term) && strlen($term) < 10) {
            return User::find((int) $term);
        }

        return User::where('phone', $term)->orWhere('email', $term)->first();
    }
}
