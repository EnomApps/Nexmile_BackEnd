@php
    /** @var \App\Models\Merchant $merchant */

    // Explicit patterns: 'merchants.*' would match every tab at once.
    $tabs = [
        ['route' => 'merchants.dashboard', 'pattern' => 'merchants.dashboard', 'label' => __('portal.nav.dashboard')],
        ['route' => 'merchants.menu.index', 'pattern' => 'merchants.menu.*', 'label' => __('portal.nav.menu')],
        ['route' => 'merchants.orders.index', 'pattern' => 'merchants.orders.*', 'label' => __('portal.nav.orders')],
        ['route' => 'merchants.storefront.edit', 'pattern' => 'merchants.storefront.*', 'label' => __('portal.nav.storefront')],
    ];
@endphp

<div class="flex flex-wrap items-start justify-between gap-4">
    <div>
        <h1 class="text-3xl font-extrabold tracking-tight text-white">{{ $merchant->business_name }}</h1>
        <p class="mt-1 text-sm text-gray-500">
            {{ __('portal.dashboard.signed_in_as') }} {{ auth()->user()->name }}
        </p>
    </div>
    <form method="POST" action="{{ route('merchants.logout') }}">
        @csrf
        <button type="submit" class="text-sm font-medium text-gray-400 hover:text-brand-green">
            {{ __('portal.dashboard.logout') }}
        </button>
    </form>
</div>

<nav class="mt-6 flex gap-1 border-b border-white/10 overflow-x-auto" aria-label="{{ __('portal.dashboard.title') }}">
    @foreach ($tabs as $tab)
        @php $active = request()->routeIs($tab['pattern']); @endphp
        <a href="{{ route($tab['route']) }}"
           @if ($active) aria-current="page" @endif
           class="px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px whitespace-nowrap transition
                  {{ $active
                        ? 'border-brand-green text-white'
                        : 'border-transparent text-gray-500 hover:text-gray-300' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>
