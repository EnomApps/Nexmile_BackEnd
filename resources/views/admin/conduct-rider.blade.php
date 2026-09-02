@extends('layouts.admin')

@section('title', $rider->full_name)

@section('content')

@php
    $card = 'rounded-2xl border border-white/10 bg-white/[0.02] p-5';
@endphp

<section class="max-w-4xl mx-auto px-4 sm:px-6 py-10">

    <a href="{{ route('admin.conduct.index') }}" class="text-sm text-gray-500 hover:text-gray-300">&larr; Conduct</a>

    <h1 class="mt-3 text-2xl font-bold text-white">{{ $rider->full_name }}</h1>
    <p class="mt-1 text-sm text-gray-500">
        {{ $rider->user?->phone }}
        &middot; {{ $rider->vehicle_type }}
        &middot; {{ $rider->completed_deliveries }} deliveries
        &middot; account {{ $rider->user?->status?->value }}
    </p>

    @if ($standing['flagged'])
        <div class="mt-6 {{ $card }} border-amber-400/30 bg-amber-400/[0.04]">
            <p class="font-bold text-amber-200">Flagged for review</p>
            <p class="mt-1 text-sm text-amber-100/80">{{ implode(' · ', $standing['reasons']) }}</p>
            <p class="mt-3 text-xs text-amber-100/60 max-w-xl leading-relaxed">
                This is a prompt to read the file, not a decision. Suspending an
                account is on the rider's own page under Verification — it is
                deliberately not a button here.
            </p>
        </div>
    @endif

    <div class="mt-6 grid sm:grid-cols-3 gap-4">
        <div class="{{ $card }}">
            <p class="text-2xl font-extrabold text-white">
                {{ $standing['rating'] !== null ? number_format($standing['rating'], 1) : '—' }}
            </p>
            <p class="mt-1 text-xs text-gray-500">
                Rating
                @if ($rider->rating_count)
                    <span class="text-gray-600">({{ $rider->rating_count }})</span>
                @endif
            </p>
        </div>
        <div class="{{ $card }}">
            <p class="text-2xl font-extrabold text-white">{{ $standing['warnings'] }}</p>
            <p class="mt-1 text-xs text-gray-500">Warnings in {{ $windowDays }} days</p>
        </div>
        <div class="{{ $card }}">
            <p class="text-2xl font-extrabold text-white">{{ $reports->where('status', 'pending')->count() }}</p>
            <p class="mt-1 text-xs text-gray-500">Reports waiting</p>
        </div>
    </div>

    {{-- A warning with no report behind it — something a merchant raised, or
         an admin witnessed. Recorded honestly rather than by inventing a
         complaint that nobody made. --}}
    <div class="mt-6 {{ $card }}">
        <h2 class="font-bold text-white">Record a warning</h2>
        <p class="mt-1 text-sm text-gray-500">For something raised outside the app — a merchant complaint, or a call.</p>

        <form method="POST" action="{{ route('admin.conduct.warn', $rider) }}" class="mt-4 flex flex-wrap gap-2">
            @csrf
            <input name="reason" required minlength="5" maxlength="255"
                   placeholder="What happened, and who reported it"
                   class="flex-1 min-w-[16rem] rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white
                          focus:border-brand-orange focus:ring-1 focus:ring-brand-orange outline-none">
            <button class="px-4 py-2 rounded-lg border border-red-400/40 text-red-300 font-bold text-sm hover:bg-red-500/10 transition">
                Record warning
            </button>
        </form>
    </div>

    <h2 class="mt-8 text-sm font-bold uppercase tracking-widest text-gray-500">Warnings</h2>
    <div class="mt-3 space-y-2">
        @forelse ($warnings as $warning)
            <div class="{{ $card }}">
                <p class="text-sm text-gray-200">{{ $warning->reason }}</p>
                <p class="mt-1 text-xs text-gray-600">
                    {{ $warning->issuedBy?->name ?? 'unknown' }}
                    &middot; {{ $warning->created_at?->format('j M Y') }}
                    @if ($warning->created_at?->lt(now()->subDays($windowDays)))
                        &middot; <span class="text-gray-700">expired</span>
                    @endif
                </p>
            </div>
        @empty
            <p class="text-sm text-gray-600">None.</p>
        @endforelse
    </div>

    <h2 class="mt-8 text-sm font-bold uppercase tracking-widest text-gray-500">Reports</h2>
    <div class="mt-3 space-y-2">
        @forelse ($reports as $report)
            <div class="{{ $card }}">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm {{ $report->isSerious() ? 'text-red-300' : 'text-gray-200' }}">
                        {{ $report->label() }}
                    </p>
                    <span class="text-xs text-gray-600">
                        #{{ $report->order?->order_number }}
                        &middot; {{ $report->created_at?->format('j M Y') }}
                        &middot; {{ ucfirst($report->status) }}
                    </span>
                </div>

                @if ($report->description)
                    <p class="mt-2 text-sm text-gray-400">{{ $report->description }}</p>
                @endif

                @if ($report->review_note)
                    <p class="mt-2 text-xs text-gray-600">
                        {{ $report->review_note }} — {{ $report->reviewer?->name ?? 'unknown' }}
                    </p>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-600">None.</p>
        @endforelse
    </div>

</section>

@endsection
