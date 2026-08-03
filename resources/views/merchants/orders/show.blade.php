@extends('layouts.site')

@section('title', '#'.$order->order_number)

@section('content')

@php
    use App\Enums\OrderStatus;

    $card = 'rounded-2xl border border-white/10 bg-white/[0.02] p-6';
    $row = 'flex justify-between gap-4 text-sm';
@endphp

<section class="max-w-3xl mx-auto px-4 sm:px-6 py-12">

    <a href="{{ route('merchants.orders.index') }}" class="text-sm text-gray-500 hover:text-brand-green">
        &larr; {{ __('portal.orders.back') }}
    </a>

    <div class="mt-4 flex flex-wrap items-center gap-3">
        <h1 class="text-3xl font-extrabold tracking-tight text-white">#{{ $order->order_number }}</h1>
        <span class="rounded-md bg-white/10 px-2.5 py-1 text-xs font-bold text-gray-300">
            {{ __('portal.status.'.$order->status->value) }}
        </span>
    </div>
    <p class="mt-1 text-sm text-gray-500">
        {{ $order->isDelivery() ? __('portal.orders.delivery') : __('portal.orders.pickup') }}
        @if ($order->placed_at)
            <span class="mx-1.5 text-gray-700">·</span>
            {{ $order->placed_at->format('d M Y, g:i a') }}
        @endif
    </p>

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

    {{-- Actions --}}
    @if (! $order->status->isTerminal())
        <div class="mt-8 {{ $card }} space-y-4">
            <div class="flex flex-wrap gap-3">
                @if ($order->status === OrderStatus::Placed)
                    <form method="POST" action="{{ route('merchants.orders.accept', $order->id) }}" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <div>
                            <label for="prep_minutes" class="block text-xs font-medium mb-1.5 text-gray-400">
                                {{ __('portal.orders.prep_minutes') }}
                            </label>
                            <input id="prep_minutes" name="prep_minutes" type="number" min="1" max="120"
                                   value="{{ $merchant->avg_prep_time_minutes ?? 20 }}"
                                   class="w-28 rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white
                                          focus:border-brand-green focus:ring-1 focus:ring-brand-green outline-none">
                        </div>
                        <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-green text-black font-bold text-sm hover:bg-lime-400 transition">
                            {{ __('portal.orders.accept') }}
                        </button>
                    </form>
                @elseif ($order->status === OrderStatus::Accepted)
                    <form method="POST" action="{{ route('merchants.orders.preparing', $order->id) }}">
                        @csrf
                        <button type="submit" class="px-5 py-2.5 rounded-lg bg-blue-500 text-black font-bold text-sm hover:bg-blue-400 transition">
                            {{ __('portal.orders.start_preparing') }}
                        </button>
                    </form>
                @endif

                @if (in_array($order->status, [OrderStatus::Accepted, OrderStatus::Preparing], true))
                    <form method="POST" action="{{ route('merchants.orders.ready', $order->id) }}">
                        @csrf
                        <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-green text-black font-bold text-sm hover:bg-lime-400 transition">
                            {{ __('portal.orders.mark_ready') }}
                        </button>
                    </form>
                @endif
            </div>

            @if ($order->status === OrderStatus::Placed)
                <form method="POST" action="{{ route('merchants.orders.reject', $order->id) }}"
                      class="pt-4 border-t border-white/10 space-y-2">
                    @csrf
                    <label for="reason" class="block text-xs font-medium text-gray-400">
                        {{ __('portal.orders.reject_reason') }}
                    </label>
                    <div class="flex flex-wrap gap-2">
                        <input id="reason" name="reason" required minlength="10" maxlength="255"
                               class="flex-1 min-w-[14rem] rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white
                                      focus:border-red-400 focus:ring-1 focus:ring-red-400 outline-none">
                        <button type="submit" class="px-4 py-2 rounded-lg border border-red-400/40 text-red-300 font-bold text-sm hover:bg-red-500/10 transition">
                            {{ __('portal.orders.reject') }}
                        </button>
                    </div>
                    <p class="text-xs text-gray-600">{{ __('portal.orders.reject_hint') }}</p>
                </form>
            @endif
        </div>
    @endif

    {{-- Items --}}
    <div class="mt-8 {{ $card }}">
        <h2 class="font-bold text-white">{{ __('portal.orders.items') }}</h2>

        <div class="mt-4 space-y-3">
            @foreach ($order->items as $item)
                <div class="{{ $row }} border-b border-white/10 pb-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="inline-block h-3 w-3 shrink-0 rounded-sm border {{ $item->is_veg ? 'border-brand-green' : 'border-red-500' }}"></span>
                            <span class="text-gray-200">{{ $item->quantity }}× {{ $item->name }}</span>
                        </div>
                        @foreach ($item->options as $option)
                            <div class="ml-5 text-xs text-gray-500">{{ $option->group_name }}: {{ $option->name }}</div>
                        @endforeach
                        @if ($item->notes)
                            <div class="ml-5 mt-1 text-xs text-brand-orange">{{ $item->notes }}</div>
                        @endif
                    </div>
                    <span class="text-gray-300 shrink-0">₹{{ number_format((float) $item->line_total, 2) }}</span>
                </div>
            @endforeach
        </div>

        <dl class="mt-5 space-y-2">
            @foreach ([
                __('portal.orders.items') => $order->items_total,
                __('portal.orders.total') => $order->grand_total,
                __('portal.orders.payout') => $order->merchant_payout,
            ] as $label => $amount)
                <div class="{{ $row }} {{ $label === __('portal.orders.payout') ? 'pt-2 border-t border-white/10 font-bold text-white' : 'text-gray-400' }}">
                    <dt>{{ $label }}</dt>
                    <dd>₹{{ number_format((float) $amount, 2) }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    {{-- Customer --}}
    <div class="mt-8 {{ $card }}">
        <h2 class="font-bold text-white">{{ __('portal.orders.customer') }}</h2>
        <p class="mt-3 text-sm text-gray-300">
            {{ $order->delivery_contact_name ?? $order->customer?->name }}
        </p>
        @if ($order->isDelivery())
            <p class="mt-1 text-sm text-gray-500">
                {{ collect([$order->delivery_line1, $order->delivery_line2, $order->delivery_landmark, $order->delivery_city, $order->delivery_pincode])->filter()->join(', ') }}
            </p>
        @endif
        @if ($order->customer_note)
            <p class="mt-3 rounded-lg bg-brand-orange/10 border border-brand-orange/30 px-3 py-2 text-sm text-brand-orange">
                {{ __('portal.orders.note') }}: {{ $order->customer_note }}
            </p>
        @endif
        @if ($order->cancellation_reason)
            <p class="mt-3 text-sm text-red-300">{{ $order->cancellation_reason }}</p>
        @endif
    </div>

    {{-- Timeline --}}
    @if ($order->statusHistory->isNotEmpty())
        <div class="mt-8 {{ $card }}">
            <h2 class="font-bold text-white">{{ __('portal.orders.timeline') }}</h2>
            <ol class="mt-4 space-y-3">
                @foreach ($order->statusHistory as $entry)
                    <li class="{{ $row }} text-gray-400">
                        <span>{{ __('portal.status.'.$entry->to_status->value) }}</span>
                        <span class="text-gray-600 shrink-0">{{ $entry->created_at?->format('d M, g:i a') }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
    @endif

</section>

@endsection
