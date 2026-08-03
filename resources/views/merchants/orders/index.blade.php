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
    <h2 class="mt-8 text-xl font-bold text-white">{{ __('portal.orders.live') }}</h2>

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

@endsection
