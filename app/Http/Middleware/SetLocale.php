<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the visitor's chosen language for the request.
 *
 * Two kinds of caller, two places to look:
 *
 *   the portal — the locale the merchant picked, held in the session by the
 *                language switcher.
 *   the apps   — the `Accept-Language` header, because a token request has
 *                no session to hold anything in.
 *
 * Both fall back to `APP_LOCALE`.
 *
 * **This decides the language of the response, not of notifications.** A push
 * is rendered inside a queue worker long after the request that caused it,
 * and a worker has no header to read — so push copy comes from the
 * recipient's `users.preferred_locale` instead (see PushService). An app that
 * lets a rider choose a language should therefore do both: send the header,
 * and PATCH the choice onto the profile. Sending only the header gives Tamil
 * screens and English notifications.
 */
class SetLocale
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->fromSession($request) ?? $this->fromHeader($request);

        if ($locale !== null) {
            App::setLocale($locale);
        }

        return $next($request);
    }

    /** The portal's language switcher. */
    protected function fromSession(Request $request): ?string
    {
        // Token requests have no session at all, and asking an absent one for
        // a key throws rather than answering null.
        if (! $request->hasSession()) {
            return null;
        }

        $locale = $request->session()->get('locale');

        return $this->supported(is_string($locale) ? $locale : null);
    }

    /**
     * The best language the client asked for that we actually have.
     *
     * Quality values are honoured, so `ta;q=0.9,en;q=0.8` is Tamil rather
     * than whichever happened to be listed first. A region is dropped back
     * to its language — `ta-IN` and `ta-LK` are both served by `lang/ta`,
     * and refusing them over the suffix would hand a Tamil phone English.
     */
    protected function fromHeader(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            $locale = $this->supported($language)
                ?? $this->supported(strtok(str_replace('_', '-', $language), '-'));

            if ($locale !== null) {
                return $locale;
            }
        }

        return null;
    }

    /** Null for anything we hold no translations for. */
    protected function supported(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }

        $locale = strtolower($locale);

        return array_key_exists($locale, config('site.locales')) ? $locale : null;
    }
}
