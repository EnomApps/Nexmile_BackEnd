@extends('layouts.site')

@section('title', __('portal.menu.title'))

@section('content')

@php
    /** @var \Illuminate\Support\Collection $items */
    // Grouped in PHP rather than a query per category: one pass over a list a
    // merchant can realistically scroll.
    $grouped = $items->groupBy('category_id');
    $pill = 'px-3 py-1.5 rounded-lg text-xs font-bold transition';
@endphp

<section class="max-w-5xl mx-auto px-4 sm:px-6 py-12">

    @include('merchants.partials.nav')

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

    @unless ($merchant->isKycVerified())
        <p class="mt-6 rounded-lg bg-brand-orange/10 border border-brand-orange/30 text-brand-orange px-4 py-3 text-sm">
            {{ __('portal.menu.locked') }}
        </p>
    @endunless

    <div class="mt-8 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-white">{{ __('portal.menu.title') }}</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-xl">{{ __('portal.menu.intro') }}</p>
        </div>
        <a href="{{ route('merchants.menu.items.create') }}"
           class="px-5 py-2.5 rounded-lg bg-brand-green text-black font-bold text-sm hover:bg-lime-400 transition">
            {{ __('portal.menu.add_item') }}
        </a>
    </div>

    {{-- Categories --}}
    <div class="mt-8 rounded-2xl border border-white/10 bg-white/[0.02] p-6">
        <h3 class="font-bold text-white">{{ __('portal.menu.categories') }}</h3>

        <div class="mt-4 flex flex-wrap gap-2">
            @forelse ($categories as $category)
                <span class="inline-flex items-center gap-2 rounded-lg border border-white/15 bg-black/30 px-3 py-1.5 text-sm text-gray-300">
                    {{ $category->name }}
                    <span class="text-xs text-gray-600">{{ $category->menu_items_count }}</span>
                    <form method="POST" action="{{ route('merchants.menu.categories.destroy', $category->id) }}"
                          onsubmit="return confirm('{{ __('portal.menu.category_delete_confirm') }}')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-gray-600 hover:text-red-400 leading-none" aria-label="{{ __('portal.menu.delete') }}">&times;</button>
                    </form>
                </span>
            @empty
                <p class="text-sm text-gray-600">{{ __('portal.menu.no_categories') }}</p>
            @endforelse
        </div>

        <form method="POST" action="{{ route('merchants.menu.categories.store') }}" class="mt-5 flex flex-wrap gap-2">
            @csrf
            <label for="category-name" class="sr-only">{{ __('portal.menu.category_name') }}</label>
            <input id="category-name" name="name" required maxlength="255"
                   placeholder="{{ __('portal.menu.category_name') }}"
                   class="flex-1 min-w-[12rem] rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white
                          focus:border-brand-green focus:ring-1 focus:ring-brand-green outline-none">
            <button type="submit" class="px-4 py-2 rounded-lg border border-white/15 text-sm font-semibold text-gray-300 hover:text-white hover:border-white/30 transition">
                {{ __('portal.menu.add_category') }}
            </button>
        </form>
    </div>

    {{-- Items, grouped by category --}}
    @if ($items->isEmpty())
        <p class="mt-8 rounded-2xl border border-white/10 bg-white/[0.02] p-10 text-center text-sm text-gray-500">
            {{ __('portal.menu.no_items') }}
        </p>
    @else
        @foreach ($categories->push(null) as $category)
            @php
                $group = $grouped[$category?->id] ?? collect();
            @endphp
            @continue ($group->isEmpty())

            <div class="mt-8">
                <h3 class="text-sm font-bold uppercase tracking-widest text-gray-500">
                    {{ $category?->name ?? __('portal.menu.uncategorised') }}
                </h3>

                <div class="mt-3 space-y-3">
                    @foreach ($group as $item)
                        <div class="rounded-xl border border-white/10 bg-black/30 p-4 flex flex-wrap items-center gap-4 justify-between
                                    {{ $item->is_available ? '' : 'opacity-60' }}">
                            <div class="flex items-center gap-4 min-w-0">
                                @php $url = $images->url($item->image_path); @endphp
                                @if ($url)
                                    <img src="{{ $url }}" alt="" width="56" height="56"
                                         class="h-14 w-14 rounded-lg object-cover shrink-0" loading="lazy">
                                @else
                                    <div class="h-14 w-14 rounded-lg bg-white/5 shrink-0" aria-hidden="true"></div>
                                @endif

                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-block h-3 w-3 shrink-0 rounded-sm border
                                                     {{ $item->is_veg ? 'border-brand-green' : 'border-red-500' }}"
                                              title="{{ $item->is_veg ? __('portal.menu.veg') : __('portal.menu.non_veg') }}"></span>
                                        <span class="font-medium text-white truncate">{{ $item->name }}</span>
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500">
                                        <span class="text-gray-300">₹{{ number_format((float) $item->price, 2) }}</span>
                                        @if ($item->isDiscounted())
                                            <span class="ml-1.5 line-through text-gray-600">₹{{ number_format((float) $item->compare_at_price, 2) }}</span>
                                        @endif
                                        <span class="mx-1.5 text-gray-700">·</span>
                                        {{ $item->prep_time_minutes }} {{ __('portal.menu.minutes') }}
                                        <span class="mx-1.5 text-gray-700">·</span>
                                        <span class="{{ $item->is_available ? 'text-brand-green' : 'text-brand-orange' }}">
                                            {{ $item->is_available ? __('portal.menu.available') : __('portal.menu.unavailable') }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                <form method="POST" action="{{ route('merchants.menu.items.toggle', $item->id) }}">
                                    @csrf
                                    <button type="submit"
                                            class="{{ $pill }} {{ $item->is_available
                                                ? 'border border-white/15 text-gray-400 hover:text-brand-orange hover:border-brand-orange/40'
                                                : 'bg-brand-green text-black hover:bg-lime-400' }}">
                                        {{ $item->is_available ? __('portal.menu.mark_unavailable') : __('portal.menu.mark_available') }}
                                    </button>
                                </form>

                                <a href="{{ route('merchants.menu.items.options.index', $item->id) }}"
                                   class="{{ $pill }} border border-white/15 text-gray-300 hover:text-white hover:border-white/30">
                                    {{ __('portal.options.manage') }}
                                    @if ($item->option_groups_count)
                                        <span class="ml-1 text-gray-600">{{ $item->option_groups_count }}</span>
                                    @endif
                                </a>

                                <a href="{{ route('merchants.menu.items.edit', $item->id) }}"
                                   class="{{ $pill }} border border-white/15 text-gray-300 hover:text-white hover:border-white/30">
                                    {{ __('portal.menu.edit_item') }}
                                </a>

                                <form method="POST" action="{{ route('merchants.menu.items.destroy', $item->id) }}"
                                      onsubmit="return confirm('{{ __('portal.menu.delete_confirm') }}')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="{{ $pill }} border border-white/15 text-gray-500 hover:text-red-400 hover:border-red-400/40">
                                        {{ __('portal.menu.delete') }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif

</section>

@endsection
