@extends('layouts.admin')

@section('title', 'Reviews')

@section('content')

@php
    $card = 'rounded-2xl border border-white/10 bg-white/[0.02] p-5';
@endphp

<section class="max-w-5xl mx-auto px-4 sm:px-6 py-10">

    <h1 class="text-2xl font-bold text-white">Reviews</h1>
    <p class="mt-1 text-sm text-gray-500 max-w-2xl leading-relaxed">
        Hiding a review removes it from the app and recalculates the
        restaurant's score and every dish it rated. The row is kept — it is
        evidence if a customer disputes the takedown, and it defends a merchant
        accused of buying ratings.
    </p>

    <nav class="mt-6 flex flex-wrap gap-2">
        @foreach ([
            'commented' => 'With a comment',
            'low' => '1–2 stars',
            'hidden' => 'Hidden',
            'all' => 'Everything',
        ] as $key => $label)
            <a href="{{ route('admin.reviews.index', ['show' => $key]) }}"
               class="px-3.5 py-1.5 rounded-lg border text-sm font-medium transition
                      {{ $show === $key
                            ? 'border-brand-orange text-brand-orange bg-brand-orange/10'
                            : 'border-white/15 text-gray-400 hover:text-white' }}">
                {{ $label }}
            </a>
        @endforeach
    </nav>

    <div class="mt-6 space-y-3">
        @forelse ($reviews as $review)
            <div class="{{ $card }} {{ $review->isHidden() ? 'opacity-60' : '' }}">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="px-2.5 py-1 rounded-lg text-sm font-bold
                                     {{ $review->rating >= 4 ? 'bg-brand-green/15 text-brand-green'
                                        : ($review->rating >= 3 ? 'bg-amber-400/15 text-amber-300'
                                        : 'bg-red-500/15 text-red-300') }}">
                            {{ $review->rating }}★
                        </span>
                        <div>
                            <p class="text-sm text-gray-200">{{ $review->merchant?->business_name }}</p>
                            <p class="text-xs text-gray-600">
                                {{ $review->user?->name ?? 'Deleted account' }}
                                @if ($review->order) &middot; #{{ $review->order->order_number }} @endif
                                &middot; {{ $review->created_at?->format('j M Y') }}
                            </p>
                        </div>
                    </div>

                    @if ($review->isHidden())
                        <span class="text-xs font-semibold text-red-300">Hidden</span>
                    @endif
                </div>

                @if ($review->comment)
                    <p class="mt-3 text-sm text-gray-300 leading-relaxed">{{ $review->comment }}</p>
                @endif

                @if ($review->isHidden())
                    <div class="mt-3 pt-3 border-t border-white/10 flex flex-wrap items-center justify-between gap-3">
                        <p class="text-xs text-gray-500">
                            {{ $review->hidden_reason }}
                            <span class="text-gray-600">
                                — {{ $review->hiddenBy?->name ?? 'unknown' }},
                                {{ $review->hidden_at?->format('j M Y') }}
                            </span>
                        </p>
                        <form method="POST" action="{{ route('admin.reviews.unhide', $review) }}">
                            @csrf
                            <button class="text-xs font-semibold text-gray-300 hover:text-white">Restore</button>
                        </form>
                    </div>
                @else
                    <form method="POST" action="{{ route('admin.reviews.hide', $review) }}"
                          class="mt-3 pt-3 border-t border-white/10 flex flex-wrap gap-2">
                        @csrf
                        <label for="reason-{{ $review->id }}" class="sr-only">Reason</label>
                        <input id="reason-{{ $review->id }}" name="reason" required minlength="5" maxlength="255"
                               placeholder="Why is this being taken down?"
                               class="flex-1 min-w-[14rem] rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white
                                      focus:border-red-400 focus:ring-1 focus:ring-red-400 outline-none">
                        <button class="px-4 py-2 rounded-lg border border-red-400/40 text-red-300 font-bold text-sm hover:bg-red-500/10 transition">
                            Hide
                        </button>
                    </form>
                @endif
            </div>
        @empty
            <div class="{{ $card }}">
                <p class="text-sm text-gray-500">Nothing here.</p>
            </div>
        @endforelse
    </div>

    <div class="mt-6">
        {{ $reviews->links() }}
    </div>

</section>

@endsection
