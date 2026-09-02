@extends('layouts.admin')

@section('title', 'Rider conduct')

@section('content')

@php
    $card = 'rounded-2xl border border-white/10 bg-white/[0.02] p-5';
    $input = 'flex-1 min-w-[14rem] rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white
              focus:border-brand-orange focus:ring-1 focus:ring-brand-orange outline-none';
@endphp

<section class="max-w-5xl mx-auto px-4 sm:px-6 py-10">

    <h1 class="text-2xl font-bold text-white">Rider conduct</h1>
    <p class="mt-1 text-sm text-gray-500 max-w-2xl leading-relaxed">
        What customers reported, and what was decided about it. Nothing happens
        to a rider automatically — a report is what a customer said, a warning
        is what Nexmile decided after reading it.
        {{ $threshold }} warnings inside {{ $windowDays }} days flags a rider
        for review. That is a prompt to read their file, not a dismissal.
    </p>

    {{-- Riders whose file needs reading, whether or not anything is pending.
         A rider nobody reports but everybody scores 2 never appears in a queue
         built from complaints alone. --}}
    @if ($flagged->isNotEmpty())
        <div class="mt-6 {{ $card }} border-amber-400/30 bg-amber-400/[0.04]">
            <h2 class="font-bold text-amber-200">Needs review</h2>
            <div class="mt-3 space-y-2">
                @foreach ($flagged as $row)
                    <a href="{{ route('admin.conduct.rider', $row['rider']) }}"
                       class="flex flex-wrap items-center justify-between gap-3 border-b border-white/5 pb-2 group">
                        <span class="text-sm text-gray-200 group-hover:text-white">
                            {{ $row['rider']->full_name }}
                        </span>
                        <span class="text-xs text-amber-200/80">
                            {{ implode(' · ', $row['standing']['reasons']) }}
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <nav class="mt-6 flex flex-wrap gap-2">
        @foreach (['pending' => 'Waiting to be read', 'serious' => 'Safety and money', 'all' => 'Everything'] as $key => $label)
            <a href="{{ route('admin.conduct.index', ['show' => $key]) }}"
               class="px-3.5 py-1.5 rounded-lg border text-sm font-medium transition
                      {{ $show === $key
                            ? 'border-brand-orange text-brand-orange bg-brand-orange/10'
                            : 'border-white/15 text-gray-400 hover:text-white' }}">
                {{ $label }}
            </a>
        @endforeach
    </nav>

    <div class="mt-6 space-y-3">
        @forelse ($reports as $report)
            <div class="{{ $card }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold {{ $report->isSerious() ? 'text-red-300' : 'text-gray-200' }}">
                            {{ $report->label() }}
                            @if ($report->isSerious())
                                <span class="ml-2 text-xs font-normal text-red-300/70">safety or money</span>
                            @endif
                        </p>
                        <p class="mt-0.5 text-xs text-gray-600">
                            <a href="{{ route('admin.conduct.rider', $report->rider_id) }}" class="hover:text-gray-300">
                                {{ $report->rider?->full_name }}
                            </a>
                            &middot; #{{ $report->order?->order_number }}
                            &middot; reported by {{ \Illuminate\Support\Str::before($report->reporter?->name ?? '', ' ') }}
                            &middot; {{ $report->created_at?->format('j M, g:i a') }}
                        </p>
                    </div>

                    <span class="text-xs font-semibold
                                 {{ $report->status === 'upheld' ? 'text-red-300'
                                    : ($report->status === 'dismissed' ? 'text-gray-500' : 'text-amber-300') }}">
                        {{ ucfirst($report->status) }}
                    </span>
                </div>

                @if ($report->description)
                    <p class="mt-3 text-sm text-gray-300 leading-relaxed">{{ $report->description }}</p>
                @endif

                @if ($report->status === 'pending')
                    <div class="mt-4 pt-3 border-t border-white/10 space-y-2">
                        <form method="POST" action="{{ route('admin.conduct.uphold', $report) }}"
                              class="flex flex-wrap gap-2">
                            @csrf
                            <input name="note" required minlength="5" maxlength="255"
                                   placeholder="What is the rider being warned for? Goes on their record."
                                   class="{{ $input }}">
                            <button class="px-4 py-2 rounded-lg border border-red-400/40 text-red-300 font-bold text-sm hover:bg-red-500/10 transition">
                                Uphold and warn
                            </button>
                        </form>

                        <form method="POST" action="{{ route('admin.conduct.dismiss', $report) }}"
                              class="flex flex-wrap gap-2">
                            @csrf
                            <input name="note" required minlength="5" maxlength="255"
                                   placeholder="Why is this being dismissed? The next person reading the file needs it."
                                   class="{{ $input }}">
                            <button class="px-4 py-2 rounded-lg border border-white/15 text-gray-300 font-semibold text-sm hover:text-white transition">
                                Dismiss
                            </button>
                        </form>
                    </div>
                @elseif ($report->review_note)
                    <p class="mt-3 pt-3 border-t border-white/10 text-xs text-gray-500">
                        {{ $report->review_note }}
                        <span class="text-gray-600">
                            — {{ $report->reviewer?->name ?? 'unknown' }},
                            {{ $report->reviewed_at?->format('j M Y') }}
                        </span>
                    </p>
                @endif
            </div>
        @empty
            <div class="{{ $card }}">
                <p class="text-sm text-gray-500">Nothing here.</p>
            </div>
        @endforelse
    </div>

    <div class="mt-6">{{ $reports->links() }}</div>

</section>

@endsection
