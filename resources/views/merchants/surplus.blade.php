@extends('layouts.site')

@section('title', __('portal.surplus.title'))

@section('content')

@php
    $card = 'rounded-2xl border border-white/10 bg-white/[0.02] p-6';
    $input = 'rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white
              focus:border-brand-green focus:ring-1 focus:ring-brand-green outline-none';
@endphp

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

    <div class="mt-8">
        <h2 class="text-xl font-bold text-white">{{ __('portal.surplus.title') }}</h2>
        <p class="mt-1 text-sm text-gray-500 max-w-2xl">{{ __('portal.surplus.intro') }}</p>
    </div>

    {{-- Running deals --}}
    <h3 class="mt-8 text-sm font-bold uppercase tracking-widest text-gray-500">{{ __('portal.surplus.running') }}</h3>

    @if ($deals->isEmpty())
        <p class="mt-3 {{ $card }} text-center text-sm text-gray-500">{{ __('portal.surplus.none_running') }}</p>
    @else
        <div class="mt-3 space-y-3">
            @foreach ($deals as $item)
                @php $live = $surplus->isLive($item); @endphp
                <div class="rounded-xl border {{ $live ? 'border-brand-green/30 bg-brand-green/[0.05]' : 'border-white/10 bg-black/30' }} p-4
                            flex flex-wrap items-center justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-white">{{ $item->name }}</span>
                            <span class="rounded-md px-2 py-0.5 text-xs font-bold
                                         {{ $live ? 'bg-brand-green/20 text-brand-green' : 'bg-white/10 text-gray-500' }}">
                                {{ $live ? __('portal.surplus.live') : __('portal.surplus.not_live') }}
                            </span>
                        </div>

                        <div class="mt-1 text-xs text-gray-500">
                            <span class="text-brand-green font-semibold">₹{{ number_format((float) $item->price, 2) }}</span>
                            <span class="ml-1.5 line-through text-gray-600">₹{{ number_format((float) $item->compare_at_price, 2) }}</span>
                            <span class="mx-1.5 text-gray-700">·</span>
                            {{ trans_choice('portal.surplus.left', (int) $item->surplus_quantity, ['count' => (int) $item->surplus_quantity]) }}
                            @if ($item->surplus_available_until)
                                <span class="mx-1.5 text-gray-700">·</span>
                                {{ __('portal.surplus.until') }}
                                {{ $item->surplus_available_until->format('d M, g:i a') }}
                            @endif
                        </div>
                    </div>

                    <form method="POST" action="{{ route('merchants.surplus.destroy', $item->id) }}"
                          onsubmit="return confirm('{{ __('portal.surplus.withdraw_confirm') }}')">
                        @csrf @method('DELETE')
                        <button class="px-3 py-1.5 rounded-lg border border-white/15 text-xs font-bold text-gray-400 hover:text-brand-orange hover:border-brand-orange/40 transition">
                            {{ __('portal.surplus.withdraw') }}
                        </button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Offer something --}}
    <h3 class="mt-10 text-sm font-bold uppercase tracking-widest text-gray-500">{{ __('portal.surplus.offer_one') }}</h3>

    @if ($candidates->isEmpty())
        <p class="mt-3 {{ $card }} text-center text-sm text-gray-500">{{ __('portal.surplus.no_candidates') }}</p>
    @else
        <div class="mt-3 space-y-3">
            @foreach ($candidates as $item)
                <form method="POST" action="{{ route('merchants.surplus.store', $item->id) }}"
                      class="{{ $card }} flex flex-wrap items-end gap-3">
                    @csrf

                    <div class="flex-1 min-w-[10rem]">
                        <p class="font-medium text-white">{{ $item->name }}</p>
                        <p class="mt-0.5 text-xs text-gray-600">
                            {{ __('portal.surplus.usual_price') }} ₹{{ number_format((float) $item->price, 2) }}
                        </p>
                        <input type="hidden" name="compare_at_price" value="{{ (float) $item->price }}">
                    </div>

                    <div>
                        <label for="price-{{ $item->id }}" class="block text-xs text-gray-500 mb-1">{{ __('portal.surplus.deal_price') }} (₹)</label>
                        <input id="price-{{ $item->id }}" name="price" type="number" step="0.01" min="1" required
                               value="{{ round((float) $item->price / 2, 2) }}" class="{{ $input }} w-28">
                    </div>

                    <div>
                        <label for="qty-{{ $item->id }}" class="block text-xs text-gray-500 mb-1">{{ __('portal.surplus.portions') }}</label>
                        <input id="qty-{{ $item->id }}" name="surplus_quantity" type="number" min="1" max="500" required
                               value="5" class="{{ $input }} w-24">
                    </div>

                    <div>
                        <label for="from-{{ $item->id }}" class="block text-xs text-gray-500 mb-1">{{ __('portal.surplus.from') }}</label>
                        <input id="from-{{ $item->id }}" name="surplus_available_from" type="datetime-local" required
                               value="{{ now()->format('Y-m-d\TH:i') }}" class="{{ $input }}">
                    </div>

                    <div>
                        <label for="until-{{ $item->id }}" class="block text-xs text-gray-500 mb-1">{{ __('portal.surplus.until_label') }}</label>
                        <input id="until-{{ $item->id }}" name="surplus_available_until" type="datetime-local" required
                               value="{{ now()->addHours(3)->format('Y-m-d\TH:i') }}" class="{{ $input }}">
                    </div>

                    <button class="px-5 py-2 rounded-lg bg-brand-green text-black text-sm font-bold hover:bg-lime-400 transition">
                        {{ __('portal.surplus.offer') }}
                    </button>
                </form>
            @endforeach
        </div>
    @endif

    <p class="mt-6 text-xs text-gray-600">{{ __('portal.surplus.note') }}</p>

</section>

@endsection
