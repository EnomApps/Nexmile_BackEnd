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
        const INTERVAL = 30000;
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
        function chime() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                [880, 1320].forEach(function (freq, i) {
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.frequency.value = freq;
                    const at = ctx.currentTime + i * 0.18;
                    gain.gain.setValueAtTime(0.0001, at);
                    gain.gain.exponentialRampToValueAtTime(0.3, at + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.16);
                    osc.start(at);
                    osc.stop(at + 0.18);
                });
            } catch (e) {
                // An unsupported browser loses the chime, not the queue.
            }
        }

        function paint() {
            if (!label) return;
            label.textContent = toggle.dataset[soundOn() ? 'on' : 'off'];
        }

        if (toggle) {
            toggle.addEventListener('click', function () {
                localStorage.setItem(SOUND_KEY, soundOn() ? 'off' : 'on');
                paint();
                // Browsers only allow audio after a gesture, so the tap that
                // turns it on is also what unlocks it. Playing here proves to
                // the merchant that it works.
                if (soundOn()) chime();
            });
            paint();
        }

        const lastSeen = Number(localStorage.getItem(KEY) || 0);

        // Only chime for something that arrived after this browser last looked,
        // never on a merchant's very first visit.
        if (lastSeen > 0 && newest > lastSeen && soundOn()) {
            chime();
        }

        if (newest > 0) localStorage.setItem(KEY, String(newest));

        let elapsed = 0;

        setInterval(function () {
            elapsed += 1000;
            if (elapsed < INTERVAL) return;

            const el = document.activeElement;
            const typing = el && ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName);

            if (typing || document.hidden) return;

            window.location.reload();
        }, 1000);
    })();
</script>

@endsection
