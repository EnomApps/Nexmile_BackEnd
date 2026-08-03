@php
    /** @var \App\Models\Order $order */
    /** @var bool $actionable */

    $tone = match ($order->status->value) {
        'placed' => 'bg-brand-orange/15 text-brand-orange',
        'accepted', 'preparing' => 'bg-blue-500/15 text-blue-300',
        'ready_for_pickup', 'rider_assigned', 'picked_up' => 'bg-brand-green/15 text-brand-green',
        'delivered' => 'bg-white/10 text-gray-300',
        default => 'bg-red-500/15 text-red-300',
    };
@endphp

<div class="rounded-xl border border-white/10 bg-black/30 p-4">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('merchants.orders.show', $order->id) }}"
                   class="font-bold text-white hover:text-brand-green">
                    #{{ $order->order_number }}
                </a>
                <span class="rounded-md px-2 py-0.5 text-xs font-bold {{ $tone }}">
                    {{ __('portal.status.'.$order->status->value) }}
                </span>
                <span class="text-xs text-gray-600">
                    {{ $order->isDelivery() ? __('portal.orders.delivery') : __('portal.orders.pickup') }}
                </span>
            </div>

            <p class="mt-1.5 text-xs text-gray-500">
                {{ $order->items->sum('quantity') }} {{ __('portal.orders.items') }}
                <span class="mx-1.5 text-gray-700">·</span>
                <span class="text-gray-300">₹{{ number_format((float) $order->grand_total, 2) }}</span>
                @if ($order->placed_at)
                    <span class="mx-1.5 text-gray-700">·</span>
                    {{ $order->placed_at->diffForHumans() }}
                @endif
            </p>

            <p class="mt-1 text-xs text-gray-600 truncate">
                {{ $order->items->map(fn ($i) => $i->quantity.'× '.$i->name)->join(', ') }}
            </p>
        </div>

        @if ($actionable)
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                @if ($order->status === \App\Enums\OrderStatus::Placed)
                    <form method="POST" action="{{ route('merchants.orders.accept', $order->id) }}">
                        @csrf
                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-green text-black text-xs font-bold hover:bg-lime-400 transition">
                            {{ __('portal.orders.accept') }}
                        </button>
                    </form>
                @elseif ($order->status === \App\Enums\OrderStatus::Accepted)
                    <form method="POST" action="{{ route('merchants.orders.preparing', $order->id) }}">
                        @csrf
                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-blue-500 text-black text-xs font-bold hover:bg-blue-400 transition">
                            {{ __('portal.orders.start_preparing') }}
                        </button>
                    </form>
                @endif

                @if (in_array($order->status, [\App\Enums\OrderStatus::Accepted, \App\Enums\OrderStatus::Preparing], true))
                    <form method="POST" action="{{ route('merchants.orders.ready', $order->id) }}">
                        @csrf
                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-green text-black text-xs font-bold hover:bg-lime-400 transition">
                            {{ __('portal.orders.mark_ready') }}
                        </button>
                    </form>
                @endif

                <a href="{{ route('merchants.orders.show', $order->id) }}"
                   class="px-3 py-1.5 rounded-lg border border-white/15 text-xs font-bold text-gray-300 hover:text-white hover:border-white/30 transition">
                    {{ __('portal.orders.view') }}
                </a>
            </div>
        @else
            <a href="{{ route('merchants.orders.show', $order->id) }}"
               class="px-3 py-1.5 rounded-lg border border-white/15 text-xs font-bold text-gray-400 hover:text-white hover:border-white/30 transition shrink-0">
                {{ __('portal.orders.view') }}
            </a>
        @endif
    </div>
</div>
