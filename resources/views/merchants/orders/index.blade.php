@extends('layouts.site')

@section('title', __('portal.orders.title'))

@section('content')

<section class="max-w-5xl mx-auto px-4 sm:px-6 py-12">

    @include('merchants.partials.nav')

    {{-- Also where the script writes, so a tap that no longer reloads the page
         still says out loud what it did. --}}
    <div id="flash" role="status" aria-live="polite"
         class="mt-6 rounded-lg bg-brand-green/10 border border-brand-green/30 text-brand-green px-4 py-3 text-sm
                {{ session('status') ? '' : 'hidden' }}">
        {{ session('status') }}
    </div>

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

            {{-- A timestamp printed once by the server stops being true the
                 moment the page stops reloading, and a clock that is wrong
                 about freshness is worse than none. The script keeps it. --}}
            <p class="text-xs text-gray-600">
                {{ __('portal.orders.updated') }}
                <span id="updated-at">{{ now()->format('g:i:s a') }}</span>
            </p>
        </div>
    </div>

    {{-- The highest live order id this page knows about. The script compares it
         with what this browser saw last, so a chime only ever means something
         genuinely new arrived. --}}
    <span id="newest-order" class="hidden" data-id="{{ $newest_order_id }}"></span>

    {{-- Everything inside is replaced in place. The document, and with it the
         permission to make a sound, survives the whole shift. --}}
    <div id="queue" data-signature="{{ $signature }}">
        @include('merchants.orders.partials.lists')
    </div>

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
         * Nothing below ever reloads the page.
         *
         * Reloading was why the alert stopped working: every reload is a fresh
         * document, and a browser blocks audio in a document the user has not
         * yet interacted with. The tap that unlocked the sound had happened in
         * the document before — so the ring worked once, and the first accept
         * or the first refresh silenced it for the rest of the shift.
         *
         * One document that lives all day keeps that permission. It is also
         * simply faster: a tap costs a few kilobytes of markup instead of the
         * whole page, stylesheet and script again over a shop's connection.
         */
        const queue = document.getElementById('queue');
        const flash = document.getElementById('flash');
        const stamp = document.getElementById('updated-at');
        const csrf = @json(csrf_token());

        let known = newest;

        function touched() {
            if (stamp) stamp.textContent = new Date().toLocaleTimeString();
        }

        function say(message) {
            if (!flash || !message) return;

            flash.textContent = message;
            flash.classList.remove('hidden');
        }

        /** Swap the lists, if the server sent new ones. */
        function apply(payload) {
            if (payload.changed && typeof payload.html === 'string') {
                queue.innerHTML = payload.html;
                queue.dataset.signature = payload.signature;
            }

            touched();

            const latest = Number(payload.newest_order_id || 0);

            /*
             * Ring only for an order this browser has not seen. Marking one
             * ready changes the queue too, and a chime for the merchant's own
             * tap is the kind of noise that gets the sound switched off.
             */
            if (latest > known) {
                known = latest;
                localStorage.setItem(KEY, String(latest));

                if (soundOn()) startRinging();
            }
        }

        /** True when the session has gone and the answer is a login page. */
        function signedOut(res) {
            if (res.status !== 401 && res.status !== 419) return false;

            // Reloading lets the merchant sign in again, rather than watching
            // a queue that will never update.
            window.location.reload();

            return true;
        }

        async function check() {
            if (document.hidden) return;

            try {
                const url = @json(route('merchants.orders.queue-status'))
                    + '?known=' + encodeURIComponent(queue.dataset.signature || '');

                const res = await fetch(url, {headers: {'Accept': 'application/json'}});

                if (signedOut(res) || !res.ok) return;

                apply(await res.json());
            } catch (e) {
                // A dropped connection in a shop is normal. Try again next tick.
            }
        }

        /*
         * Accept, start preparing and mark ready, without leaving the page.
         *
         * Delegated from the container because the buttons inside it are
         * replaced every time the queue changes — a listener bound to a button
         * would not survive the first swap.
         */
        queue.addEventListener('submit', async function (event) {
            const form = event.target;

            if (!(form instanceof HTMLFormElement)) return;

            event.preventDefault();

            const button = form.querySelector('button');

            /*
             * A cook tapping twice because the first tap looked like nothing
             * happened would otherwise send the order forward two steps.
             */
            if (button) {
                if (button.disabled) return;

                button.disabled = true;
                button.classList.add('opacity-50');
            }

            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: new FormData(form),
                });

                if (signedOut(res)) return;

                if (!res.ok) {
                    // Rare — a status that moved underneath them, usually.
                    // The page tells the truth after a reload, so let it.
                    window.location.reload();

                    return;
                }

                const payload = await res.json();

                say(payload.message);
                apply(payload);
            } catch (e) {
                // The tap did not land. Give the button back rather than
                // leaving a dead control in front of a busy kitchen.
                if (button) {
                    button.disabled = false;
                    button.classList.remove('opacity-50');
                }
            }
        });

        setInterval(check, INTERVAL);

        // Coming back to the tab is exactly when a merchant wants the truth.
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) check();
        });
    })();
</script>

@endsection
