@extends('layouts.admin')

@section('title', 'App feedback')

@section('content')

@php
    $input = 'rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white
              focus:border-brand-orange focus:ring-1 focus:ring-brand-orange outline-none';

    $card = 'rounded-2xl border border-white/10 bg-white/[0.02] px-5 py-4';
@endphp

<section class="max-w-6xl mx-auto px-4 sm:px-6 py-10">

    <h1 class="text-2xl font-bold text-white">App feedback</h1>
    <p class="mt-1 text-sm text-gray-500 max-w-3xl leading-relaxed">
        What customers say about the app itself, from the profile page. Not
        reviews of restaurants or riders — those live under
        <a href="{{ route('admin.reviews.index') }}" class="text-brand-orange hover:underline">Reviews</a>
        and
        <a href="{{ route('admin.conduct.index') }}" class="text-brand-orange hover:underline">Conduct</a>.
    </p>

    <div class="mt-6 grid gap-3 sm:grid-cols-4">
        <div class="{{ $card }}">
            <div class="text-xs text-gray-500">Average</div>
            <div class="mt-1 text-2xl font-bold text-white">
                @if ($summary['average'] === null)
                    <span class="text-gray-600">—</span>
                @else
                    {{ number_format($summary['average'], 2) }}
                @endif
            </div>
        </div>

        <div class="{{ $card }}">
            <div class="text-xs text-gray-500">One and two star</div>
            {{-- Its own figure because an average hides the shape: fifty fives
                 and fifty ones average the same as a hundred threes. --}}
            <div class="mt-1 text-2xl font-bold {{ $summary['low'] > 0 ? 'text-amber-300' : 'text-white' }}">
                {{ $summary['low'] }}
            </div>
        </div>

        <div class="{{ $card }}">
            <div class="text-xs text-gray-500">Last 7 days</div>
            <div class="mt-1 text-2xl font-bold text-white">{{ $summary['last_7_days'] }}</div>
        </div>

        <div class="{{ $card }}">
            <div class="text-xs text-gray-500">All time</div>
            <div class="mt-1 text-2xl font-bold text-white">{{ $summary['total'] }}</div>
        </div>
    </div>

    <form method="GET" action="{{ route('admin.feedback.index') }}" class="mt-6 flex flex-wrap items-end gap-3">
        <div>
            <label for="score" class="block text-xs text-gray-500 mb-1.5">Rating</label>
            <select id="score" name="score" class="{{ $input }}">
                <option value="">Any</option>
                <option value="low" @selected($filters['score'] === 'low')>1 – 2 stars</option>
                <option value="high" @selected($filters['score'] === 'high')>4 – 5 stars</option>
            </select>
        </div>

        <div>
            <label for="platform" class="block text-xs text-gray-500 mb-1.5">Platform</label>
            <select id="platform" name="platform" class="{{ $input }}">
                <option value="">Any</option>
                <option value="android" @selected($filters['platform'] === 'android')>Android</option>
                <option value="ios" @selected($filters['platform'] === 'ios')>iOS</option>
            </select>
        </div>

        @if ($versions->isNotEmpty())
            <div>
                <label for="version" class="block text-xs text-gray-500 mb-1.5">Build</label>
                <select id="version" name="version" class="{{ $input }}">
                    <option value="">Any</option>
                    @foreach ($versions as $version)
                        <option value="{{ $version }}" @selected($filters['version'] === $version)>{{ $version }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <button class="px-5 py-2 rounded-lg bg-brand-orange text-black text-sm font-bold hover:bg-orange-400">
            Filter
        </button>

        @if ($filters['score'] || $filters['platform'] || $filters['version'])
            <a href="{{ route('admin.feedback.index') }}" class="px-3 py-2 text-sm text-gray-500 hover:text-white">
                Clear
            </a>
        @endif
    </form>

    <div class="mt-6 space-y-3">
        @forelse ($feedback as $item)
            <article class="rounded-2xl border border-white/10 overflow-hidden">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 bg-white/[0.03] text-sm">
                    <span class="font-bold {{ $item->rating <= 2 ? 'text-amber-300' : 'text-brand-green' }}">
                        {{ $item->rating }}/5
                    </span>

                    <span class="text-gray-300">
                        @if ($item->user)
                            {{ $item->user->name }}
                            <span class="text-xs text-gray-600">{{ $item->user->phone }}</span>
                        @else
                            {{-- nullOnDelete: the account closed, the sentence stayed. --}}
                            <span class="text-gray-600">account closed</span>
                        @endif
                    </span>

                    <span class="text-xs text-gray-600">
                        {{ $item->platform ? ucfirst($item->platform) : 'unknown platform' }}
                        @if ($item->app_version) · {{ $item->app_version }} @endif
                    </span>

                    <span class="ml-auto text-xs text-gray-600">
                        {{ $item->created_at->timezone(config('app.timezone'))->format('d M Y, g:i a') }}
                    </span>
                </div>

                @if (filled($item->message))
                    <p class="px-5 py-4 text-sm text-gray-300 leading-relaxed whitespace-pre-line">{{ $item->message }}</p>
                @else
                    <p class="px-5 py-4 text-sm text-gray-600 italic">A rating with no comment.</p>
                @endif
            </article>
        @empty
            <div class="rounded-2xl border border-white/10 px-5 py-12 text-center text-sm text-gray-600">
                No feedback matches that.
            </div>
        @endforelse
    </div>

    <div class="mt-6">{{ $feedback->links() }}</div>

</section>

@endsection
