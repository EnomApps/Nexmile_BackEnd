{{-- The part of the queue page that changes.

     Kept separate so the page can replace it in place. Everything a reload
     used to cost — the layout, the stylesheet, the nav, the script, and the
     audio permission that dies with the document — is outside this file. --}}

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
