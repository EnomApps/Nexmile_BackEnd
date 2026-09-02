@extends('layouts.site')

@section('title', __('portal.orders.title'))

@section('content')

<section class="max-w-5xl mx-auto px-4 sm:px-6 py-12">

    @include('merchants.partials.nav')

    @if (session('status'))
        <div class="mt-6 rounded-lg bg-brand-green/10 border border-brand-green/30 text-brand-green px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mt-6 rounded-lg bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 text-sm space-y-1">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    @unless ($merchant->is_accepting_orders)
        <p class="mt-6 rounded-lg bg-brand-orange/10 border border-brand-orange/30 text-brand-orange px-4 py-3 text-sm">
            {{ __('portal.orders.closed') }}
        </p>
    @endunless

    {{-- Live queue --}}
    <div class="mt-8 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-xl font-bold text-white">{{ __('portal.orders.live') }}</h2>

        <div class="flex flex-wrap items-center gap-3">
            <button type="button" id="sound-toggle"
                    data-on="{{ __('portal.orders.sound_on') }}"
                    data-off="{{ __('portal.orders.sound_off') }}"
                    class="px-3 py-1.5 rounded-lg border border-white/15 text-xs font-semibold text-gray-400 hover:text-white hover:border-white/30 transition">
                <span id="sound-label">{{ __('portal.orders.sound_off') }}</span>
            </button>

            <p class="text-xs text-gray-600">
                {{ __('portal.orders.auto_refresh', ['seconds' => 30]) }}
                · {{ __('portal.orders.updated') }} {{ now()->format('g:i:s a') }}
            </p>
        </div>
    </div>

    {{-- The highest live order id this page knows about. The script compares it
         with what this browser saw last, so a chime only ever means something
         genuinely new arrived. --}}
    <span id="newest-order" class="hidden" data-id="{{ $live->max('id') ?? 0 }}"></span>

    @if ($live->isEmpty())
        <p class="mt-3 rounded-2xl border border-white/10 bg-white/[0.02] p-10 text-center text-sm text-gray-500">
            {{ __('portal.orders.no_live') }}
        </p>
    @else
        <div class="mt-3 space-y-3">
            @foreach ($live as $order)
                @include('merchants.orders.partials.card', ['order' => $order, 'actionable' => true])
            @endforeach
        </div>
    @endif

    {{-- History --}}
    <h2 class="mt-12 text-xl font-bold text-white">{{ __('portal.orders.history') }}</h2>

    @if ($history->isEmpty())
        <p class="mt-3 rounded-2xl border border-white/10 bg-white/[0.02] p-10 text-center text-sm text-gray-500">
            {{ __('portal.orders.no_history') }}
        </p>
    @else
        <div class="mt-3 space-y-3">
            @foreach ($history as $order)
                @include('merchants.orders.partials.card', ['order' => $order, 'actionable' => false])
            @endforeach
        </div>
    @endif

</section>

{{-- A kitchen queue nobody refreshes is a queue orders sit in unnoticed, and a
     refresh nobody hears is one a busy cook still misses. Both are the poor
     relation of a push notification and will be replaced by one; doing nothing
     until then means lost orders.

     Reloading is held back while a form is focused or the tab is hidden —
     doing it under someone mid-type is worse than a list 30 seconds stale. --}}
<script>
    (function () {
        const INTERVAL = 10000;
        const KEY = 'nexmile.orders.lastSeen';
        const SOUND_KEY = 'nexmile.orders.sound';
        const marker = document.getElementById('newest-order');
        const newest = Number(marker ? marker.dataset.id : 0);

        const toggle = document.getElementById('sound-toggle');
        const label = document.getElementById('sound-label');

        function soundOn() {
            return localStorage.getItem(SOUND_KEY) === 'on';
        }

        /*
         * Generated rather than loaded: a two-tone chime needs no asset, no
         * build step, and nothing to 404 on a slow connection in a shop.
         */
        /*
         * A new order rings until someone acknowledges it, or for RING_MS.
         *
         * A single chime is missed by a cook at the stove with an extractor
         * running, and a missed order goes cold while the customer watches a
         * timer. Ringing until it is heard is the point.
         *
         * It stops the instant anyone touches the screen. An alert that keeps
         * going after you have seen it is one people learn to mute, and a
         * muted alert is worse than a quiet one.
         */
        const RING_MS = 10000;
        // Long enough to recognise as the same alert, short enough that
        // testing it is not a punishment.
        const PREVIEW_MS = 2700;
        const PATTERN_MS = 900;

        let ringTimer = null;
        let ringStopAt = 0;

        let audio = null;

        /**
         * One AudioContext for the page.
         *
         * Browsers cap how many a page may create — around six in Chrome — so
         * building one per beep would leave a ten-second ring going silent
         * partway through. Silence halfway is worse than no alert: the cook
         * learns the sound is unreliable and stops listening for it.
         *
         * Created on first use, because a context made before any user gesture
         * starts suspended.
         */
        function context() {
            if (audio === null) {
                audio = new (window.AudioContext || window.webkitAudioContext)();
            }

            // A tab left in the background suspends it; resuming is what makes
            // the alert work when the kitchen comes back to the screen.
            if (audio.state === 'suspended') {
                audio.resume();
            }

            return audio;
        }

        function beep(ctx, freq, offset) {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.value = freq;
            const at = ctx.currentTime + offset;
            gain.gain.setValueAtTime(0.0001, at);
            gain.gain.exponentialRampToValueAtTime(0.35, at + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.16);
            osc.start(at);
            osc.stop(at + 0.18);
        }

        function chime() {
            try {
                const ctx = context();
                beep(ctx, 880, 0);
                beep(ctx, 1320, 0.18);
            } catch (e) {
                // An unsupported browser loses the chime, not the queue.
            }
        }

        function stopRinging() {
            if (ringTimer !== null) {
                clearInterval(ringTimer);
                ringTimer = null;
            }
        }

        function startRinging(durationMs) {
            stopRinging();

            ringStopAt = Date.now() + (durationMs || RING_MS);
            chime();

            ringTimer = setInterval(function () {
                if (Date.now() >= ringStopAt) {
                    stopRinging();

                    return;
                }

                chime();
            }, PATTERN_MS);
        }

        /*
         * Any sign of a person stops it. Touch and keydown as well as click,
         * because a kitchen tablet is tapped rather than clicked and a cook
         * with wet hands may hit a key instead.
         */
        ['click', 'touchstart', 'keydown'].forEach(function (event) {
            document.addEventListener(event, stopRinging, { passive: true });
        });

        function paint() {
            if (!label) return;
            label.textContent = toggle.dataset[soundOn() ? 'on' : 'off'];
        }

        if (toggle) {
            toggle.addEventListener('click', function () {
                localStorage.setItem(SOUND_KEY, soundOn() ? 'off' : 'on');
                paint();
                /*
                 * Browsers only allow audio after a gesture, so the tap that
                 * turns sound on is also what unlocks it.
                 *
                 * It previews the real alert rather than a single chime. This
                 * button is how a merchant checks the alert works, so it has
                 * to sound like the alert — a preview that differs from the
                 * thing it is previewing tells them nothing. Shortened,
                 * because ten seconds of ringing every time somebody toggles
                 * the switch is its own reason to leave it off.
                 *
                 * Deferred past this very click, because the document listener
                 * that stops the ring would otherwise catch the toggle tap as
                 * it bubbles and silence the preview before it is heard.
                 */
                if (soundOn()) setTimeout(function () { startRinging(PREVIEW_MS); }, 0);
            });
            paint();
        }

        const lastSeen = Number(localStorage.getItem(KEY) || 0);

        // Only ring for something that arrived after this browser last looked,
        // never on a merchant's very first visit.
        if (lastSeen > 0 && newest > lastSeen && soundOn()) {
            startRinging();
        }

        if (newest > 0) localStorage.setItem(KEY, String(newest));

        /*
         * The queue polls rather than reloading itself.
         *
         * Reloading was the reason the alert never sounded: every reload is a
         * fresh document, and a browser blocks audio in a document the user
         * has not yet interacted with. The tap that unlocked the sound always
         * happened in the document before, so the ring was reliably silent.
         *
         * One long-lived document keeps that permission, and drops the delay
         * from up to thirty seconds to ten.
         */
        let known = newest;

        async function check() {
            if (document.hidden || ringTimer !== null) return;

            try {
                const res = await fetch(@json(route('merchants.orders.queue-status')), {
                    headers: {'Accept': 'application/json'},
                });

                // A signed-out session answers with a redirect to the login
                // page, not JSON. Reloading lets the merchant sign in again
                // rather than watching a queue that will never update.
                if (res.status === 401 || res.status === 419) {
                    window.location.reload();

                    return;
                }

                if (!res.ok) return;

                const latest = Number((await res.json()).newest_order_id || 0);

                if (latest <= known) return;

                known = latest;
                localStorage.setItem(KEY, String(latest));

                if (soundOn()) startRinging();

                /*
                 * The list is refreshed only once the ring has finished. A
                 * reload mid-alert cuts the audio, which is the failure this
                 * whole change exists to remove.
                 */
                setTimeout(function () {
                    window.location.reload();
                }, RING_MS + 500);
            } catch (e) {
                // A dropped connection in a shop is normal. Try again next tick.
            }
        }

        setInterval(check, INTERVAL);

        // Coming back to the tab is exactly when a merchant wants the truth.
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) check();
        });
    })();
</script>

@endsection
