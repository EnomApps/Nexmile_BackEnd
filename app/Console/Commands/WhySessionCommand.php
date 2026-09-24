<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * "Signing in returns 419 Page Expired. Why?"
 *
 * A CSRF failure means the token in the form did not match the one in the
 * session — and by far the most common cause is not the token at all, but the
 * session: if the cookie never comes back, every request starts a new one and
 * no token can ever match.
 *
 * Every check below is something that produces exactly that symptom while
 * looking perfectly healthy from the outside. A cookie scoped to the wrong
 * domain, a secure cookie on a plain-http request, a session table that was
 * never migrated — none of them log anything, and all of them look like "login
 * is broken" from a browser.
 */
class WhySessionCommand extends Command
{
    protected $signature = 'nexmile:why-419';

    protected $description = 'Explain why signing in returns 419 Page Expired';

    private int $problems = 0;

    public function handle(): int
    {
        $this->line('');
        $this->line('  <options=bold>Session and CSRF</>');
        $this->line('');

        $this->appKey();
        $this->store();
        $this->cookieDomain();
        $this->secureCookie();
        $this->lifetime();
        $this->writes();

        $this->line('');

        if ($this->problems === 0) {
            $this->info('  Nothing here explains a 419.');
            $this->line('');
            $this->line('  The likely cause is then the ordinary one: a sign-in page');
            $this->line('  left open longer than the session lifetime. That is now');
            $this->line('  handled — it sends the person back to the form rather than');
            $this->line('  to a blank error page.');
            $this->line('');
            $this->line('  If it happens on a <options=bold>freshly loaded</> page every time,');
            $this->line('  check whether anything in front of the app caches HTML:');
            $this->line('  a cached login page serves one token to everybody.');
            $this->line('');

            return self::SUCCESS;
        }

        $this->line('');

        return self::FAILURE;
    }

    /** Without it the session cookie cannot be decrypted, so every request is new. */
    private function appKey(): void
    {
        $key = config('app.key');

        $this->check(
            ! empty($key),
            'APP_KEY is set',
            'APP_KEY is empty. The session cookie cannot be decrypted, so every request starts a new session and no CSRF token can ever match.',
        );
    }

    /** The driver, and whether the thing it writes to actually exists. */
    private function store(): void
    {
        $driver = config('session.driver');
        $this->line("  Driver: <options=bold>{$driver}</>");

        if ($driver === 'database') {
            $table = config('session.table', 'sessions');

            $this->check(
                Schema::hasTable($table),
                "Session table `{$table}` exists",
                "Session table `{$table}` is missing — the migration never ran. Run `php artisan migrate --force`.",
            );

            return;
        }

        if ($driver === 'redis') {
            try {
                // A write and a read, because a reachable Redis that refuses
                // writes fails in exactly the way this is looking for.
                $probe = 'nexmile:session-probe:'.Str::random(8);
                cache()->store('redis')->put($probe, '1', 10);
                $ok = cache()->store('redis')->get($probe) === '1';
                cache()->store('redis')->forget($probe);

                $this->check($ok, 'Redis accepts writes',
                    'Redis is reachable but a value written could not be read back.');
            } catch (\Throwable $e) {
                $this->check(false, 'Redis reachable',
                    'Redis is not reachable: '.$e->getMessage().'. Sessions cannot be stored, so every request starts a new one.');
            }
        }
    }

    /**
     * The one that catches people, and the one nothing reports.
     *
     * A cookie scoped to api.nexmile.in is simply not sent back when the
     * browser is on nexmile.in. Every request then starts a fresh session and
     * the login form can never succeed, however many times it is tried.
     */
    private function cookieDomain(): void
    {
        $domain = config('session.domain');
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($domain === null) {
            $this->check(true, 'SESSION_DOMAIN unset (cookie scoped to the host that sets it)');

            return;
        }

        $matches = $appHost !== null
            && (Str::endsWith($appHost, ltrim($domain, '.')) || $appHost === $domain);

        $this->check(
            $matches,
            "SESSION_DOMAIN ({$domain}) covers APP_URL ({$appHost})",
            "SESSION_DOMAIN is {$domain} but APP_URL is {$appHost}. The browser will not send the session cookie back, so every request starts a new session. Either clear SESSION_DOMAIN or set it to a domain that covers the host people actually visit.",
        );
    }

    /** A secure cookie is never sent over plain http, and never says so. */
    private function secureCookie(): void
    {
        $secure = config('session.secure');
        $https = Str::startsWith((string) config('app.url'), 'https://');

        if ($secure && ! $https) {
            $this->check(false, 'SESSION_SECURE_COOKIE matches the scheme',
                'SESSION_SECURE_COOKIE is true but APP_URL is not https. The cookie is never sent, so no session survives a request.');

            return;
        }

        $this->check(true, 'Cookie security matches the scheme'
            .($https && ! $secure ? ' (consider SESSION_SECURE_COOKIE=true on https)' : ''));
    }

    private function lifetime(): void
    {
        $minutes = (int) config('session.lifetime');

        $this->line("  Lifetime: <options=bold>{$minutes} minutes</>");

        if ($minutes < 60) {
            $this->line('    <comment>Short. A sign-in page left open over lunch will expire.</comment>');
        }
    }

    /** Are sessions actually being stored at all? */
    private function writes(): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $table = config('session.table', 'sessions');

        if (! Schema::hasTable($table)) {
            return;
        }

        $count = DB::table($table)->count();

        $this->line("  Stored sessions: <options=bold>{$count}</>");

        if ($count === 0) {
            $this->line('    <comment>Nothing has ever been stored. If people have been');
            $this->line('    using the site, sessions are not being written at all.</comment>');
            $this->problems++;
        }
    }

    private function check(bool $ok, string $label, ?string $problem = null): void
    {
        if ($ok) {
            $this->line("  <fg=green>OK</>    {$label}");

            return;
        }

        $this->problems++;
        $this->line("  <fg=red;options=bold>WRONG</> {$label}");

        if ($problem !== null) {
            $this->line('        <comment>'.wordwrap($problem, 66, "\n        ").'</comment>');
        }
    }
}
