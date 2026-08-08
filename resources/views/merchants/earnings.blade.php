@extends('layouts.site')

@section('title', __('portal.earnings.title'))

@section('content')

@php
    $card = 'rounded-2xl border border-white/10 bg-white/[0.02] p-5';
    $rupees = fn ($n) => '₹'.number_format((float) $n, 2);
@endphp

<section class="max-w-5xl mx-auto px-4 sm:px-6 py-12">

    @include('merchants.partials.nav')

    <div class="mt-8 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-white">{{ __('portal.earnings.title') }}</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-xl">{{ __('portal.earnings.intro') }}</p>
        </div>

        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div>
                <label for="from" class="block text-xs text-gray-500 mb-1">{{ __('portal.earnings.from') }}</label>
                <input id="from" name="from" type="date" value="{{ $from->toDateString() }}" max="{{ now()->toDateString() }}"
                       class="rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white
                              focus:border-brand-green focus:ring-1 focus:ring-brand-green outline-none">
            </div>
            <div>
                <label for="to" class="block text-xs text-gray-500 mb-1">{{ __('portal.earnings.to') }}</label>
                <input id="to" name="to" type="date" value="{{ $to->toDateString() }}" max="{{ now()->toDateString() }}"
                       class="rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white
                              focus:border-brand-green focus:ring-1 focus:ring-brand-green outline-none">
            </div>
            <button class="px-4 py-2 rounded-lg border border-white/15 text-sm font-semibold text-gray-300 hover:text-white">
                {{ __('portal.earnings.show') }}
            </button>
        </form>
    </div>

    <div class="mt-6 grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach ([
            [__('portal.earnings.delivered'), $summary['orders'], null],
            [__('portal.earnings.sales'), $rupees($summary['gross']), __('portal.earnings.food_and_packaging')],
            [__('portal.earnings.commission'), '−'.$rupees($summary['commission']), $summary['commission_rate'].'%'],
            [__('portal.earnings.payout'), $rupees($summary['payout']), __('portal.earnings.what_you_get')],
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

    <p class="mt-4 text-xs text-gray-600">
        {{ __('portal.earnings.delivered_only') }}
        @if ($summary['cancelled'])
            {{ trans_choice('portal.earnings.cancelled_note', $summary['cancelled'], ['count' => $summary['cancelled']]) }}
        @endif
    </p>

    {{-- Day by day --}}
    <div class="mt-8 overflow-x-auto rounded-2xl border border-white/10">
        <table class="w-full text-sm">
            <thead class="bg-white/[0.03] text-left text-xs uppercase tracking-wider text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-semibold">{{ __('portal.earnings.date') }}</th>
                    <th class="px-4 py-3 font-semibold text-right">{{ __('portal.earnings.delivered') }}</th>
                    <th class="px-4 py-3 font-semibold text-right">{{ __('portal.earnings.sales') }}</th>
                    <th class="px-4 py-3 font-semibold text-right">{{ __('portal.earnings.commission') }}</th>
                    <th class="px-4 py-3 font-semibold text-right">{{ __('portal.earnings.payout') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                @forelse ($daily as $row)
                    <tr class="hover:bg-white/[0.02]">
                        <td class="px-4 py-3 text-gray-300">
                            {{ \Illuminate\Support\Carbon::parse($row['date'])->format('D j M') }}
                        </td>
                        <td class="px-4 py-3 text-right text-gray-400">{{ $row['orders'] }}</td>
                        <td class="px-4 py-3 text-right text-gray-300">{{ $rupees($row['gross']) }}</td>
                        <td class="px-4 py-3 text-right text-gray-500">−{{ $rupees($row['commission']) }}</td>
                        <td class="px-4 py-3 text-right font-semibold text-brand-green">{{ $rupees($row['payout']) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-12 text-center text-sm text-gray-500">
                            {{ __('portal.earnings.none') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="mt-4 text-xs text-gray-600">{{ __('portal.earnings.settlement_note') }}</p>

</section>

@endsection
