@extends('layouts.admin')

@section('title', $order->order_number)

@section('content')

@php
    $card = 'rounded-2xl border border-white/10 bg-white/[0.02] p-6';
    $row = 'flex justify-between gap-4 border-b border-white/5 pb-2.5 text-sm';
@endphp

<section class="max-w-4xl mx-auto px-4 sm:px-6 py-10">

    <a href="{{ route('admin.orders.index') }}" class="text-sm text-gray-500 hover:text-brand-orange">&larr; Orders</a>

    <div class="mt-4 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-white">{{ $order->order_number }}</h1>
            <p class="mt-1 text-sm text-gray-500">
                {{ __('portal.status.'.$order->status->value) }}
                @if ($order->placed_at) · placed {{ $order->placed_at->diffForHumans() }} @endif
                · {{ $order->isDelivery() ? 'Delivery' : 'Self-pickup' }}
            </p>
        </div>

        @unless ($order->status->isTerminal())
            <form method="POST" action="{{ route('admin.orders.cancel', $order->id) }}"
                  class="flex items-end gap-2"
                  onsubmit="return confirm('Cancel this order? The customer sees the reason.')">
                @csrf
                <div>
                    <label for="reason" class="block text-xs text-gray-500 mb-1">Reason (shown to the customer)</label>
                    <input id="reason" name="reason" required minlength="10" maxlength="255"
                           placeholder="No rider available tonight"
                           class="rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white w-72
                                  focus:border-red-400 focus:ring-1 focus:ring-red-400 outline-none">
                </div>
                <button class="px-4 py-2 rounded-lg border border-red-400/40 text-sm font-semibold text-red-300 hover:bg-red-500/10">
                    Cancel order
                </button>
            </form>
        @endunless
    </div>

    @if ($order->cancellation_reason)
        <p class="mt-4 rounded-lg bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 text-sm">
            Cancelled by {{ $order->cancelled_by }} — {{ $order->cancellation_reason }}
        </p>
    @endif

    <div class="mt-8 grid md:grid-cols-2 gap-6">
        <div class="{{ $card }}">
            <h2 class="font-bold text-white">Parties</h2>
            <dl class="mt-4 space-y-2.5">
                @foreach ([
                    'Restaurant' => $order->merchant?->business_name,
                    'Restaurant phone' => $order->merchant?->business_phone,
                    'Customer' => $order->delivery_contact_name ?? $order->customer?->name,
                    'Customer phone' => $order->delivery_contact_phone ?? $order->customer?->phone,
                    'Rider' => $order->rider?->full_name,
                    'Rider phone' => $order->rider?->user?->phone,
                    'Vehicle' => $order->rider?->vehicle_number,
                ] as $label => $value)
                    <div class="{{ $row }} text-gray-400">
                        <dt>{{ $label }}</dt>
                        <dd class="text-right text-gray-200">{{ $value ?: '—' }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($order->isDelivery())
                <p class="mt-4 text-sm text-gray-400">
                    {{ trim(implode(', ', array_filter([
                        $order->delivery_line1, $order->delivery_line2, $order->delivery_landmark,
                        $order->delivery_city, $order->delivery_pincode,
                    ]))) ?: '—' }}
                </p>
            @endif

            @if ($order->pickup_code)
                <p class="mt-3 text-xs text-gray-600">Pickup code
                    <span class="font-mono text-gray-300">{{ $order->pickup_code }}</span>
                </p>
            @endif
        </div>

        <div class="{{ $card }}">
            <h2 class="font-bold text-white">Money</h2>
            <dl class="mt-4 space-y-2.5">
                @foreach ([
                    'Items' => $order->items_total,
                    'Packaging' => $order->packaging_fee,
                    'Delivery' => $order->delivery_fee,
                    'Discount' => $order->discount_total,
                    'Tax' => $order->tax_total,
                ] as $label => $value)
                    <div class="{{ $row }} text-gray-400">
                        <dt>{{ $label }}</dt>
                        <dd class="text-gray-200">₹{{ number_format((float) $value, 2) }}</dd>
                    </div>
                @endforeach
                <div class="flex justify-between gap-4 pt-1 text-sm font-bold text-white">
                    <dt>Total</dt>
                    <dd>₹{{ number_format((float) $order->grand_total, 2) }}</dd>
                </div>
                <div class="{{ $row }} text-gray-500 pt-3">
                    <dt>Commission</dt>
                    <dd>₹{{ number_format((float) $order->commission_amount, 2) }}</dd>
                </div>
                <div class="{{ $row }} text-gray-500">
                    <dt>Merchant payout</dt>
                    <dd>₹{{ number_format((float) $order->merchant_payout, 2) }}</dd>
                </div>
            </dl>

            @if ($order->payments->isNotEmpty())
                <p class="mt-4 text-xs text-gray-600">
                    {{ $order->payments->first()->method->value }} ·
                    {{ $order->payments->first()->status->value }}
                </p>
            @endif
        </div>
    </div>

    <div class="mt-6 {{ $card }}">
        <h2 class="font-bold text-white">Items</h2>
        <div class="mt-4 space-y-3">
            @foreach ($order->items as $item)
                <div class="flex justify-between gap-4 text-sm border-b border-white/5 pb-3">
                    <div>
                        <span class="text-gray-200">{{ $item->quantity }} × {{ $item->name }}</span>
                        @foreach ($item->options as $option)
                            <span class="block text-xs text-gray-600">{{ $option->group_name }}: {{ $option->name }}</span>
                        @endforeach
                        @if ($item->notes)
                            <span class="block text-xs text-brand-orange">{{ $item->notes }}</span>
                        @endif
                    </div>
                    <span class="text-gray-300 shrink-0">₹{{ number_format((float) $item->line_total, 2) }}</span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="mt-6 {{ $card }}">
        <h2 class="font-bold text-white">Timeline</h2>
        <ol class="mt-4 space-y-2.5">
            @foreach ($order->statusHistory->sortBy('created_at') as $entry)
                <li class="flex flex-wrap justify-between gap-3 text-sm border-b border-white/5 pb-2.5">
                    <span class="text-gray-200">{{ __('portal.status.'.$entry->to_status->value) }}</span>
                    <span class="text-gray-500">
                        {{ $entry->changedBy?->name ?? 'system' }}
                        · {{ $entry->created_at?->format('d M, g:i a') }}
                    </span>
                    @if ($entry->note)
                        <span class="w-full text-xs text-gray-500">{{ $entry->note }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>

</section>

@endsection
