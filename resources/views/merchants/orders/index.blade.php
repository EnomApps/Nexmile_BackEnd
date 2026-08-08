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
    <div class="mt-8 flex flex-wrap items-baseline justify-between gap-3">
        <h2 class="text-xl font-bold text-white">{{ __('portal.orders.live') }}</h2>
        <p class="text-xs text-gray-600">
            {{ __('portal.orders.auto_refresh', ['seconds' => 30]) }}
            · {{ __('portal.orders.updated') }} {{ now()->format('g:i:s a') }}
        </p>
    </div>

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

{{-- A kitchen queue nobody refreshes is a queue orders sit in unnoticed. This
     is the poor relation of a push notification and will be replaced by one,
     but doing nothing until then means missed orders.

     Held back while a form is focused or the tab is hidden: reloading under
     someone mid-type is worse than showing a stale list for another 30
     seconds, and a background tab does not need the traffic. --}}
<script>
    (function () {
        const INTERVAL = 30000;
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
