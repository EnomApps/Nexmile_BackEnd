@extends('layouts.admin')

@section('title', 'Orders')

@section('content')

@php
    $tabs = ['live' => 'In flight', 'stale' => 'Needs attention', 'all' => 'All'];
    $tone = fn ($status) => match ($status) {
        'placed' => 'bg-brand-orange/15 text-brand-orange',
        'accepted', 'preparing' => 'bg-blue-500/15 text-blue-300',
        'ready_for_pickup' => 'bg-yellow-500/15 text-yellow-300',
        'rider_assigned', 'picked_up' => 'bg-brand-green/15 text-brand-green',
        'delivered' => 'bg-white/10 text-gray-400',
        default => 'bg-red-500/15 text-red-300',
    };
@endphp

<section class="max-w-6xl mx-auto px-4 sm:px-6 py-10">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-white">Orders</h1>
            <p class="mt-1 text-sm text-gray-500">
                Look up an order when a customer calls, and cancel one that is stuck.
            </p>
        </div>

        <form method="GET" class="flex items-center gap-2">
            <input type="hidden" name="view" value="{{ $filters['view'] }}">
            <label for="search" class="sr-only">Order number or phone</label>
            <input id="search" name="search" value="{{ $filters['search'] ?? '' }}"
                   placeholder="NX260808ABCD or 98765…"
                   class="rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white w-64
                          focus:border-brand-orange focus:ring-1 focus:ring-brand-orange outline-none">
            <button class="px-4 py-2 rounded-lg border border-white/15 text-sm font-semibold text-gray-300 hover:text-white">
                Search
            </button>
        </form>
    </div>

    <nav class="mt-6 flex gap-1 border-b border-white/10">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('admin.orders.index', ['view' => $key]) }}"
               class="px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px transition
                      {{ $filters['view'] === $key ? 'border-brand-orange text-white' : 'border-transparent text-gray-500 hover:text-gray-300' }}">
                {{ $label }}
                @if ($key === 'stale' && $staleCount)
                    <span class="ml-1.5 rounded-md bg-red-500/20 text-red-300 px-1.5 py-0.5 text-xs">{{ $staleCount }}</span>
                @endif
            </a>
        @endforeach
    </nav>

    @if ($filters['view'] === 'stale')
        <p class="mt-4 text-sm text-gray-500">
            Ready to collect for more than {{ \App\Http\Controllers\Web\Admin\AdminOrderController::STALE_MINUTES }} minutes
            with no rider assigned. At 1&nbsp;km a rider is minutes away, so these mean something is wrong.
        </p>
    @endif

    <div class="mt-6 overflow-x-auto rounded-2xl border border-white/10">
        <table class="w-full text-sm">
            <thead class="bg-white/[0.03] text-left text-xs uppercase tracking-wider text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-semibold">Order</th>
                    <th class="px-4 py-3 font-semibold">Restaurant</th>
                    <th class="px-4 py-3 font-semibold">Customer</th>
                    <th class="px-4 py-3 font-semibold">Rider</th>
                    <th class="px-4 py-3 font-semibold">Status</th>
                    <th class="px-4 py-3 font-semibold text-right">Total</th>
                    <th class="px-4 py-3 font-semibold">Placed</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                @forelse ($orders as $order)
                    <tr class="hover:bg-white/[0.02]">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.orders.show', $order->id) }}"
                               class="font-semibold text-white hover:text-brand-orange">{{ $order->order_number }}</a>
                        </td>
                        <td class="px-4 py-3 text-gray-300">{{ $order->merchant?->business_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-400">
                            {{ $order->delivery_contact_name ?? $order->customer?->name ?? '—' }}
                            <span class="block text-xs text-gray-600">{{ $order->delivery_contact_phone ?? $order->customer?->phone }}</span>
                        </td>
                        <td class="px-4 py-3 text-gray-400">{{ $order->rider?->full_name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-md px-2 py-0.5 text-xs font-bold {{ $tone($order->status->value) }}">
                                {{ __('portal.status.'.$order->status->value) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right text-gray-300">₹{{ number_format((float) $order->grand_total, 2) }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $order->placed_at?->diffForHumans() ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-gray-500">
                            {{ $filters['view'] === 'stale' ? 'Nothing needs attention.' : 'No orders here.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $orders->links() }}</div>

</section>

@endsection
