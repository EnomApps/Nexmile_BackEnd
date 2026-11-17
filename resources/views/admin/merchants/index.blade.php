@extends('layouts.admin')

@section('title', 'Restaurants')

@section('content')

@php
    use App\Enums\KycStatus;

    $input = 'rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white
              focus:border-brand-orange focus:ring-1 focus:ring-brand-orange outline-none';

    $heading = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500';

    $flip = function (string $column) use ($filters) {
        $natural = in_array($column, ['name'], true) ? 'asc' : 'desc';

        return [
            'sort' => $column,
            'dir' => $filters['sort'] === $column
                ? ($filters['dir'] === 'asc' ? 'desc' : 'asc')
                : $natural,
        ] + array_filter([
            'q' => $filters['q'],
            'kyc' => $filters['kyc'],
            'state' => $filters['state'],
            'city' => $filters['city'],
        ], fn ($value) => $value !== null && $value !== '');
    };
@endphp

<section class="max-w-7xl mx-auto px-4 sm:px-6 py-10">

    <h1 class="text-2xl font-bold text-white">Restaurants</h1>
    <p class="mt-1 text-sm text-gray-500 max-w-3xl leading-relaxed">
        Three different things can stop a kitchen taking orders and they look
        identical from outside: the owner has <span class="text-gray-400">paused</span>,
        the clock says <span class="text-gray-400">closed</span>, or the food
        licence has <span class="text-gray-400">expired</span>. This page keeps
        them apart, because the answer you give someone on the phone depends
        entirely on which one it is.
    </p>

    <nav class="mt-6 flex flex-wrap gap-2">
        @foreach ([
            'all' => 'Everyone',
            'accepting' => 'Accepting',
            'paused' => 'Paused',
            'open' => 'Open right now',
            'closed' => 'Closed right now',
            'blocked' => 'Licence problem',
        ] as $key => $label)
            @php $active = $key === 'all' ? $filters['state'] === null : $filters['state'] === $key; @endphp
            <a href="{{ route('admin.merchants.index', array_filter([
                    'state' => $key === 'all' ? null : $key,
                    'q' => $filters['q'] ?: null,
                    'kyc' => $filters['kyc'],
                    'city' => $filters['city'],
               ])) }}"
               class="px-3.5 py-1.5 rounded-lg border text-sm font-medium transition
                      {{ $active
                            ? 'border-brand-orange text-brand-orange bg-brand-orange/10'
                            : 'border-white/15 text-gray-400 hover:text-white' }}">
                {{ $label }}
                @isset ($counts[$key])
                    <span class="ml-1 text-xs opacity-70">{{ $counts[$key] }}</span>
                @endisset
            </a>
        @endforeach
    </nav>

    <form method="GET" action="{{ route('admin.merchants.index') }}" class="mt-5 flex flex-wrap items-end gap-3">
        <input type="hidden" name="state" value="{{ $filters['state'] }}">
        <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
        <input type="hidden" name="dir" value="{{ $filters['dir'] }}">

        <div class="flex-1 min-w-[16rem]">
            <label for="q" class="block text-xs text-gray-500 mb-1.5">Search</label>
            <input id="q" name="q" value="{{ $filters['q'] }}"
                   placeholder="Name, owner, phone, FSSAI number, city or id"
                   class="w-full {{ $input }}">
        </div>

        <div>
            <label for="kyc" class="block text-xs text-gray-500 mb-1.5">Verification</label>
            <select id="kyc" name="kyc" class="{{ $input }}">
                <option value="">Any</option>
                @foreach (KycStatus::cases() as $case)
                    <option value="{{ $case->value }}" @selected($filters['kyc'] === $case->value)>
                        {{ ucfirst($case->value) }}
                    </option>
                @endforeach
            </select>
        </div>

        @if ($cities->isNotEmpty())
            <div>
                <label for="city" class="block text-xs text-gray-500 mb-1.5">City</label>
                <select id="city" name="city" class="{{ $input }}">
                    <option value="">Any</option>
                    @foreach ($cities as $city)
                        <option value="{{ $city }}" @selected($filters['city'] === $city)>{{ $city }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <button class="px-5 py-2 rounded-lg bg-brand-orange text-black text-sm font-bold hover:bg-orange-400">
            Search
        </button>

        @if ($filters['q'] !== '' || $filters['kyc'] || $filters['city'])
            <a href="{{ route('admin.merchants.index') }}" class="px-3 py-2 text-sm text-gray-500 hover:text-white">
                Clear
            </a>
        @endif
    </form>

    <div class="mt-6 rounded-2xl border border-white/10 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-white/[0.03]">
                    <tr>
                        <th class="{{ $heading }}">
                            <a href="{{ route('admin.merchants.index', $flip('name')) }}" class="hover:text-white">Restaurant</a>
                        </th>
                        <th class="{{ $heading }}">Taking orders</th>
                        <th class="{{ $heading }}">Verification</th>
                        <th class="{{ $heading }}">
                            <a href="{{ route('admin.merchants.index', $flip('orders')) }}" class="hover:text-white">Orders</a>
                        </th>
                        <th class="{{ $heading }}">
                            <a href="{{ route('admin.merchants.index', $flip('rejects')) }}" class="hover:text-white">Rejects</a>
                        </th>
                        <th class="{{ $heading }}">Menu</th>
                        <th class="{{ $heading }}">
                            <a href="{{ route('admin.merchants.index', $flip('rating')) }}" class="hover:text-white">Rating</a>
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-white/5">
                    @forelse ($merchants as $shop)
                        @php
                            $open = $shop->isOpenNow();
                            $blocker = $shop->fssaiBlocker();
                            $rate = $shop->orders_total > 0
                                ? round($shop->orders_rejected / $shop->orders_total * 100, 1)
                                : null;
                        @endphp

                        <tr class="hover:bg-white/[0.02]">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.merchants.show', $shop) }}" class="group">
                                    <div class="font-medium text-gray-200 group-hover:text-white">
                                        {{ $shop->business_name }}
                                    </div>
                                    <div class="mt-0.5 text-xs text-gray-600">
                                        #{{ $shop->id }} · {{ $shop->city }}
                                        @if ($shop->business_phone) · {{ $shop->business_phone }} @endif
                                    </div>
                                </a>

                                @if ($blocker)
                                    <div class="mt-1 text-xs text-red-300">{{ $blocker }}</div>
                                @endif

                                @if ($shop->user?->status?->value === 'suspended')
                                    <div class="mt-1 text-xs text-red-300">Account suspended</div>
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                @if (! $shop->is_accepting_orders)
                                    <span class="text-xs font-semibold text-amber-300">Paused</span>
                                @elseif (! $open)
                                    <span class="text-xs font-semibold text-gray-500">Closed now</span>
                                @else
                                    <span class="text-xs font-semibold text-brand-green">Yes</span>
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                <span class="text-xs {{ $shop->kyc_status === KycStatus::Verified ? 'text-brand-green' : 'text-gray-400' }}">
                                    {{ ucfirst($shop->kyc_status->value) }}
                                </span>
                            </td>

                            <td class="px-4 py-3 text-gray-300">{{ $shop->orders_total }}</td>

                            <td class="px-4 py-3">
                                @if ($rate === null)
                                    <span class="text-gray-600 text-xs">none yet</span>
                                @else
                                    <span class="{{ $rate >= 10 ? 'text-amber-300' : 'text-gray-300' }}">{{ $rate }}%</span>
                                    <span class="text-xs text-gray-600">({{ $shop->orders_rejected }})</span>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-xs">
                                @if ($shop->menu_items_total === 0)
                                    <span class="text-amber-300">no items</span>
                                @elseif ($shop->menu_items_total === $shop->menu_items_unavailable)
                                    {{-- Open, verified, accepting, and nothing
                                         anybody can actually order. --}}
                                    <span class="text-amber-300">all out of stock</span>
                                @else
                                    <span class="text-gray-400">
                                        {{ $shop->menu_items_total - $shop->menu_items_unavailable }} / {{ $shop->menu_items_total }}
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-gray-300">
                                @if ($shop->rating === null)
                                    <span class="text-gray-600">—</span>
                                @else
                                    {{ number_format((float) $shop->rating, 1) }}
                                    <span class="text-xs text-gray-600">({{ $shop->rating_count }})</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-sm text-gray-600">
                                No restaurants match that.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">{{ $merchants->links() }}</div>

</section>

@endsection
