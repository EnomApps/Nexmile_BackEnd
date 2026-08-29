@extends('layouts.site')

@section('title', __('portal.reviews.title'))

@section('content')

@php
    $card = 'rounded-2xl border border-white/10 bg-white/[0.02] p-6';
    $total = $breakdown->sum();
@endphp

<section class="max-w-4xl mx-auto px-4 sm:px-6 py-12">

    <h1 class="text-2xl font-bold text-white">{{ __('portal.reviews.title') }}</h1>
    <p class="mt-1 text-sm text-gray-500 max-w-2xl leading-relaxed">
        {{ __('portal.reviews.intro') }}
    </p>

    {{-- Score and the shape behind it. A 4.2 built from forty fives and ten
         ones is a different restaurant from a 4.2 where everyone said four. --}}
    <div class="mt-8 {{ $card }} flex flex-wrap gap-8">
        <div class="shrink-0">
            <p class="text-5xl font-extrabold text-white leading-none">
                {{ $merchant->rating !== null ? number_format($merchant->rating, 1) : '—' }}
            </p>
            <p class="mt-2 text-xs text-gray-500">
                {{ trans_choice('portal.reviews.count', $total, ['count' => $total]) }}
            </p>
            @if ($merchant->rating === null)
                <p class="mt-2 text-xs text-brand-orange max-w-[16rem]">
                    {{ __('portal.reviews.not_published') }}
                </p>
            @endif
        </div>

        <div class="flex-1 min-w-[14rem] space-y-1.5">
            @foreach ($breakdown as $star => $count)
                @php $pct = $total > 0 ? round($count / $total * 100) : 0; @endphp
                <a href="{{ route('merchants.reviews', $stars === $star ? [] : ['stars' => $star]) }}"
                   class="flex items-center gap-3 group">
                    <span class="w-8 text-xs {{ $stars === $star ? 'text-brand-green font-bold' : 'text-gray-500' }}">
                        {{ $star }}★
                    </span>
                    <span class="flex-1 h-2 rounded-full bg-white/10 overflow-hidden">
                        <span class="block h-full bg-brand-green" style="width: {{ $pct }}%"></span>
                    </span>
                    <span class="w-8 text-right text-xs text-gray-600 tabular-nums">{{ $count }}</span>
                </a>
            @endforeach
        </div>
    </div>

    {{-- The one thing on this page a kitchen can act on tonight. --}}
    @if ($weakest->isNotEmpty())
        <div class="mt-6 {{ $card }}">
            <h2 class="font-bold text-white">{{ __('portal.reviews.weakest') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('portal.reviews.weakest_hint') }}</p>

            <ul class="mt-4 space-y-2">
                @foreach ($weakest as $dish)
                    <li class="flex justify-between gap-4 text-sm border-b border-white/5 pb-2">
                        <span class="text-gray-200">{{ $dish->name }}</span>
                        <span class="text-gray-500 shrink-0 tabular-nums">
                            {{ number_format($dish->rating, 1) }}★
                            <span class="text-gray-600">
                                ({{ trans_choice('portal.reviews.count', $dish->rating_count, ['count' => $dish->rating_count]) }})
                            </span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($stars)
        <p class="mt-6 text-sm text-gray-400">
            {{ __('portal.reviews.filtered', ['stars' => $stars]) }}
            <a href="{{ route('merchants.reviews') }}" class="text-brand-green hover:underline">
                {{ __('portal.reviews.show_all') }}
            </a>
        </p>
    @endif

    <div class="mt-6 space-y-3">
        @forelse ($reviews as $review)
            <div class="{{ $card }}">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="px-2.5 py-1 rounded-lg text-sm font-bold
                                     {{ $review->rating >= 4 ? 'bg-brand-green/15 text-brand-green'
                                        : ($review->rating >= 3 ? 'bg-amber-400/15 text-amber-300'
                                        : 'bg-red-500/15 text-red-300') }}">
                            {{ $review->rating }}★
                        </span>
                        <span class="text-sm text-gray-300">{{ \Illuminate\Support\Str::before($review->user?->name ?? '', ' ') ?: __('portal.reviews.anonymous') }}</span>
                    </div>
                    <span class="text-xs text-gray-600">
                        {{ $review->created_at?->format('j M, g:i a') }}
                        @if ($review->order)
                            &middot; #{{ $review->order->order_number }}
                        @endif
                    </span>
                </div>

                @if ($review->comment)
                    <p class="mt-3 text-sm text-gray-300 leading-relaxed">{{ $review->comment }}</p>
                @endif

                @if ($review->items->isNotEmpty())
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($review->items as $item)
                            @continue (! $item->menuItem)
                            <span class="text-xs px-2.5 py-1 rounded-lg border border-white/10 text-gray-400">
                                {{ $item->menuItem->name }} · {{ $item->rating }}★
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <div class="{{ $card }}">
                <p class="text-sm text-gray-500">{{ __('portal.reviews.none') }}</p>
            </div>
        @endforelse
    </div>

    <div class="mt-6">
        {{ $reviews->links() }}
    </div>

</section>

@endsection
