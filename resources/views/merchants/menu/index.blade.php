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

        <p class="mt-1 text-sm text-gray-500">{{ __('portal.menu.category_image_hint') }}</p>

        <div class="mt-4 space-y-2">
            @forelse ($categories as $category)
                <div class="flex flex-wrap items-center gap-3 rounded-xl border border-white/10 bg-black/20 p-3">
                    @php $categoryImage = $images->url($category->image_path); @endphp

                    @if ($categoryImage)
                        <img src="{{ $categoryImage }}" alt="{{ $category->name }}"
                             class="w-14 h-14 rounded-lg object-cover bg-white/5 shrink-0">
                    @else
                        {{-- A dashed frame rather than a grey box: it reads as
                             something to fill in, not something that failed. --}}
                        <div class="w-14 h-14 rounded-lg border border-dashed border-white/20 shrink-0
                                    flex items-center justify-center text-gray-600 text-xl">+</div>
                    @endif

                    <div class="flex-1 min-w-[8rem]">
                        <p class="text-sm font-semibold text-gray-200">{{ $category->name }}</p>
                        <p class="text-xs text-gray-600">
                            {{ trans_choice('portal.menu.dish_count', $category->menu_items_count, ['count' => $category->menu_items_count]) }}
                        </p>
                    </div>

                    <form method="POST" action="{{ route('merchants.menu.categories.image.upload', $category->id) }}"
                          enctype="multipart/form-data" class="flex items-center gap-2">
                        @csrf
                        <label class="cursor-pointer">
                            <span class="sr-only">{{ __('portal.menu.category_image') }}</span>
                            {{-- Submits on choose: a separate Upload button is a
                                 step merchants forget, then wonder why nothing saved. --}}
                            <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp"
                                   onchange="this.form.submit()"
                                   class="block w-40 text-xs text-gray-500
                                          file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0
                                          file:text-xs file:font-semibold file:bg-white/10 file:text-gray-200
                                          hover:file:bg-white/20 file:cursor-pointer">
                        </label>
                    </form>

                    @if ($categoryImage)
                        <form method="POST" action="{{ route('merchants.menu.categories.image.destroy', $category->id) }}">
                            @csrf @method('DELETE')
                            <button class="text-xs font-semibold text-gray-500 hover:text-red-300">
                                {{ __('portal.menu.remove_photo') }}
                            </button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('merchants.menu.categories.destroy', $category->id) }}"
                          onsubmit="return confirm('{{ __('portal.menu.category_delete_confirm') }}')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-gray-600 hover:text-red-400 leading-none px-1"
                                aria-label="{{ __('portal.menu.delete') }}">&times;</button>
                    </form>
                </div>
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
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="flex items-center gap-2.5 text-sm font-bold uppercase tracking-widest text-gray-500">
                        @if ($category && $images->url($category->image_path))
                            <img src="{{ $images->url($category->image_path) }}" alt=""
                                 class="w-7 h-7 rounded-md object-cover bg-white/5">
                        @endif
                        {{ $category?->name ?? __('portal.menu.uncategorised') }}
                    </h3>

                    {{-- "No biryani today" is one decision, not one click per dish. --}}
                    @php $anyAvailable = $group->contains(fn ($i) => $i->is_available); @endphp
                    <form method="POST" action="{{ route('merchants.menu.categories.availability', $category?->id ?? 0) }}">
                        @csrf
                        <input type="hidden" name="is_available" value="{{ $anyAvailable ? 0 : 1 }}">
                        <button type="submit"
                                class="px-3 py-1 rounded-lg border border-white/10 text-xs font-semibold transition
                                       {{ $anyAvailable
                                            ? 'text-gray-500 hover:text-brand-orange hover:border-brand-orange/40'
                                            : 'text-brand-green border-brand-green/30 hover:bg-brand-green/10' }}">
                            {{ $anyAvailable ? __('portal.menu.all_unavailable') : __('portal.menu.all_available') }}
                        </button>
                    </form>
                </div>

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
