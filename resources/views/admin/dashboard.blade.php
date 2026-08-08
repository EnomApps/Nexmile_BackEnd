@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')

@php
    $card = 'rounded-2xl border border-white/10 bg-white/[0.02] p-5';
    $rupees = fn ($n) => '₹'.number_format((float) $n, 2);
@endphp

<section class="max-w-6xl mx-auto px-4 sm:px-6 py-10">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-white">
                {{ $stats['is_today'] ? 'Today' : $day->format('D j M Y') }}
            </h1>
            <p class="mt-1 text-sm text-gray-500">
                Revenue counts delivered orders only — food in a kitchen is not money yet.
            </p>
        </div>

        <form method="GET" class="flex items-center gap-2">
            <label for="day" class="sr-only">Day</label>
            <input id="day" name="day" type="date" value="{{ $day->toDateString() }}"
                   max="{{ now()->toDateString() }}"
                   class="rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white
                          focus:border-brand-orange focus:ring-1 focus:ring-brand-orange outline-none">
            <button class="px-4 py-2 rounded-lg border border-white/15 text-sm font-semibold text-gray-300 hover:text-white">
                Show
            </button>
        </form>
    </div>

    {{-- Needs someone to act --}}
    @if ($stats['attention']['kyc_waiting'] || $stats['attention']['orders_stale'])
        <div class="mt-6 flex flex-wrap gap-3">
            @if ($stats['attention']['orders_stale'])
                <a href="{{ route('admin.orders.index', ['view' => 'stale']) }}"
                   class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300 hover:bg-red-500/15">
                    <span class="font-bold">{{ $stats['attention']['orders_stale'] }}</span>
                    order{{ $stats['attention']['orders_stale'] === 1 ? '' : 's' }} ready with no rider
                </a>
            @endif

            @if ($stats['attention']['kyc_waiting'])
                <a href="{{ route('admin.index') }}"
                   class="rounded-xl border border-brand-orange/30 bg-brand-orange/10 px-4 py-3 text-sm text-brand-orange hover:bg-brand-orange/15">
                    <span class="font-bold">{{ $stats['attention']['kyc_waiting'] }}</span>
                    account{{ $stats['attention']['kyc_waiting'] === 1 ? '' : 's' }} awaiting verification
                </a>
            @endif
        </div>
    @endif

    {{-- Money --}}
    <div class="mt-6 grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach ([
            ['Gross sales', $rupees($stats['money']['gross']), 'delivered orders'],
            ['Commission earned', $rupees($stats['money']['commission']), 'your revenue'],
            ['Merchant payout', $rupees($stats['money']['merchant_payout']), 'owed to restaurants'],
            ['Average order', $rupees($stats['money']['average_order']), 'per delivered order'],
        ] as [$label, $value, $hint])
            <div class="{{ $card }}">
                <p class="text-xs uppercase tracking-wider text-gray-500">{{ $label }}</p>
                <p class="mt-2 text-2xl font-extrabold text-white">{{ $value }}</p>
                <p class="mt-1 text-xs text-gray-600">{{ $hint }}</p>
            </div>
        @endforeach
    </div>

    @if ($stats['money']['commission'] == 0 && $stats['orders']['delivered'] > 0)
        <p class="mt-4 rounded-lg bg-brand-orange/10 border border-brand-orange/30 text-brand-orange px-4 py-3 text-sm">
            Orders were delivered but no commission was earned — those restaurants are on 0%.
            Set a rate on each restaurant's page under Verification.
        </p>
    @endif

    {{-- Orders --}}
    <div class="mt-6 grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach ([
            ['Placed', $stats['orders']['placed']],
            ['Delivered', $stats['orders']['delivered']],
            ['Cancelled or rejected', $stats['orders']['cancelled']],
            ['In flight now', $stats['orders']['in_flight']],
        ] as [$label, $value])
            <div class="{{ $card }}">
                <p class="text-xs uppercase tracking-wider text-gray-500">{{ $label }}</p>
                <p class="mt-2 text-2xl font-extrabold text-white">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    {{-- Network --}}
    <div class="mt-6 grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach ([
            ['Restaurants verified', $stats['network']['merchants_verified'], null],
            ['Open right now', $stats['network']['merchants_open'], 'taking orders this minute'],
            ['Riders verified', $stats['network']['riders_verified'], null],
            ['Riders on duty', $stats['network']['riders_on_duty'], 'available or delivering'],
        ] as [$label, $value, $hint])
            <div class="{{ $card }}">
                <p class="text-xs uppercase tracking-wider text-gray-500">{{ $label }}</p>
                <p class="mt-2 text-2xl font-extrabold text-white">{{ $value }}</p>
                @if ($hint)
                    <p class="mt-1 text-xs text-gray-600">{{ $hint }}</p>
                @endif
            </div>
        @endforeach
    </div>

    @if ($stats['network']['merchants_open'] === 0 && $stats['network']['merchants_verified'] > 0)
        <p class="mt-4 rounded-lg bg-white/[0.03] border border-white/10 text-gray-400 px-4 py-3 text-sm">
            No restaurant is open right now, so customers see an empty home screen.
            Check opening hours and whether they have switched themselves on.
        </p>
    @endif

</section>

@endsection
