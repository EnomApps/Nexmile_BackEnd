@extends('layouts.admin')

@section('title', 'Home screen')

@section('content')

@php
    $card = 'rounded-2xl border border-white/10 bg-white/[0.02] p-6';
    $input = 'w-full rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white
              focus:border-brand-orange focus:ring-1 focus:ring-brand-orange outline-none';
    $label = 'block text-xs font-medium mb-1.5 text-gray-400';
    $primary = 'px-4 py-2 rounded-lg bg-brand-green text-black font-bold text-sm hover:bg-lime-400 transition';
    $danger = 'text-xs font-semibold text-red-300 hover:text-red-200';
@endphp

<section class="max-w-6xl mx-auto px-4 sm:px-6 py-10 space-y-10">

    <div>
        <h1 class="text-2xl font-bold text-white">Home screen</h1>
        <p class="mt-1 text-sm text-gray-500 max-w-2xl leading-relaxed">
            What customers see when they open the app. Changes here are live
            immediately — the app reads this order every time it loads, so
            nothing here needs an app update or an app store review.
        </p>
    </div>

    {{-- Banners ------------------------------------------------------------ --}}
    <div class="{{ $card }}">
        <h2 class="font-bold text-white">Banners</h2>
        <p class="mt-1 text-sm text-gray-500">
            The carousel at the top. Shown in position order, lowest first.
        </p>

        @if ($banners->isNotEmpty())
            <div class="mt-5 space-y-3">
                @foreach ($banners as $banner)
                    <div class="flex flex-wrap items-center gap-4 border-b border-white/5 pb-3">
                        <img src="{{ $images->url($banner->image_path) }}" alt="{{ $banner->alt_text }}"
                             class="w-28 h-16 object-cover rounded-lg bg-white/5 shrink-0">

                        <div class="flex-1 min-w-[12rem]">
                            <p class="text-sm text-gray-200">{{ $banner->alt_text }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">
                                Position {{ $banner->position }}
                                &middot; Taps to {{ $banner->action_type }}{{ $banner->action_value ? ': '.$banner->action_value : '' }}
                                @if ($banner->starts_at || $banner->ends_at)
                                    &middot; {{ $banner->starts_at?->format('j M') ?? 'always' }}
                                    to {{ $banner->ends_at?->format('j M') ?? 'no end' }}
                                @endif
                            </p>
                        </div>

                        @php
                            // Switched on is not the same as showing: a live
                            // campaign window decides that too, and an admin
                            // wondering "why can't I see it" needs both.
                            $live = $banner->is_active
                                && (! $banner->starts_at || $banner->starts_at->isPast())
                                && (! $banner->ends_at || $banner->ends_at->isFuture());
                        @endphp

                        <span class="text-xs font-semibold {{ $live ? 'text-brand-green' : 'text-gray-500' }}">
                            {{ $live ? 'Showing' : ($banner->is_active ? 'Outside its dates' : 'Switched off') }}
                        </span>

                        <div class="flex items-center gap-3">
                            <form method="POST" action="{{ route('admin.merchandising.banners.toggle', $banner) }}">
                                @csrf
                                <button class="text-xs font-semibold text-gray-300 hover:text-white">
                                    {{ $banner->is_active ? 'Switch off' : 'Switch on' }}
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.merchandising.banners.destroy', $banner) }}"
                                  onsubmit="return confirm('Remove this banner?')">
                                @csrf @method('DELETE')
                                <button class="{{ $danger }}">Remove</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="mt-5 text-sm text-gray-500">No banners yet. The carousel is hidden until there is one.</p>
        @endif

        <form method="POST" action="{{ route('admin.merchandising.banners.store') }}"
              enctype="multipart/form-data" class="mt-6 pt-5 border-t border-white/10 grid sm:grid-cols-2 gap-4">
            @csrf

            <div>
                <label for="image" class="{{ $label }}">Image</label>
                <input id="image" name="image" type="file" accept="image/*" required class="{{ $input }}">
            </div>

            <div>
                <label for="alt_text" class="{{ $label }}">Alt text</label>
                <input id="alt_text" name="alt_text" required maxlength="120" class="{{ $input }}"
                       placeholder="Items at 50% off">
                <p class="mt-1 text-xs text-gray-600">Read aloud to customers using a screen reader.</p>
            </div>

            <div>
                <label for="action_type" class="{{ $label }}">Where a tap goes</label>
                <select id="action_type" name="action_type" class="{{ $input }}">
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" class="bg-black">{{ $action }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="action_value" class="{{ $label }}">Target</label>
                <input id="action_value" name="action_value" class="{{ $input }}"
                       placeholder="under-250, a restaurant id, or a URL">
                <p class="mt-1 text-xs text-gray-600">Leave empty only when the action is "none".</p>
            </div>

            <div>
                <label for="starts_at" class="{{ $label }}">Starts (optional)</label>
                <input id="starts_at" name="starts_at" type="datetime-local" class="{{ $input }}">
            </div>

            <div>
                <label for="ends_at" class="{{ $label }}">Ends (optional)</label>
                <input id="ends_at" name="ends_at" type="datetime-local" class="{{ $input }}">
                <p class="mt-1 text-xs text-gray-600">It stops showing on its own — nobody has to be awake for it.</p>
            </div>

            <div>
                <label for="position" class="{{ $label }}">Position</label>
                <input id="position" name="position" type="number" min="0" value="0" class="{{ $input }}">
            </div>

            <div class="flex items-end">
                <button class="{{ $primary }}">Add banner</button>
            </div>
        </form>
    </div>

    {{-- Cuisines ----------------------------------------------------------- --}}
    <div class="{{ $card }}">
        <h2 class="font-bold text-white">Cuisines</h2>
        <p class="mt-1 text-sm text-gray-500">
            The round tiles under the banners. A restaurant appears under a
            cuisine when the same slug is on its profile.
        </p>

        @if ($cuisines->isNotEmpty())
            <div class="mt-5 space-y-2">
                @foreach ($cuisines as $cuisine)
                    <div class="flex items-center gap-3 rounded-xl border border-white/10 px-3 py-2">
                        @if ($cuisine->image_path)
                            <img src="{{ $images->url($cuisine->image_path) }}" alt=""
                                 class="w-11 h-11 rounded-full object-cover bg-white/5 shrink-0">
                        @else
                            {{-- Dashed, not grey: it reads as something to fill
                                 in rather than something that failed. --}}
                            <div class="w-11 h-11 rounded-full border border-dashed border-white/25 shrink-0
                                        flex items-center justify-center text-gray-600">+</div>
                        @endif

                        <div>
                            <p class="text-sm text-gray-200">{{ $cuisine->name }}</p>
                            <p class="text-xs text-gray-600 font-mono">{{ $cuisine->slug }}</p>
                        </div>

                        {{-- Submits on choose. A separate Upload button is the
                             step people forget, then wonder why nothing saved. --}}
                        <form method="POST" action="{{ route('admin.merchandising.cuisines.image.upload', $cuisine) }}"
                              enctype="multipart/form-data">
                            @csrf
                            <label class="cursor-pointer">
                                <span class="sr-only">Icon for {{ $cuisine->name }}</span>
                                <input type="file" name="image" accept="image/*" onchange="this.form.submit()"
                                       class="block w-36 text-xs text-gray-500
                                              file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0
                                              file:text-xs file:font-semibold file:bg-white/10 file:text-gray-200
                                              hover:file:bg-white/20 file:cursor-pointer">
                            </label>
                        </form>

                        @if ($cuisine->image_path)
                            <form method="POST" action="{{ route('admin.merchandising.cuisines.image.destroy', $cuisine) }}">
                                @csrf @method('DELETE')
                                <button class="text-xs font-semibold text-gray-500 hover:text-red-300">Clear</button>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('admin.merchandising.cuisines.destroy', $cuisine) }}"
                              onsubmit="return confirm('Remove {{ $cuisine->name }}? Restaurants filed under this cuisine will stop appearing in it.')">
                            @csrf @method('DELETE')
                            <button class="{{ $danger }}">&times;</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @else
            <p class="mt-5 text-sm text-gray-500">No cuisines yet. The rail is hidden until there is one.</p>
        @endif

        <form method="POST" action="{{ route('admin.merchandising.cuisines.store') }}"
              enctype="multipart/form-data" class="mt-6 pt-5 border-t border-white/10 grid sm:grid-cols-4 gap-4">
            @csrf

            <div>
                <label for="cuisine_name" class="{{ $label }}">Name</label>
                <input id="cuisine_name" name="name" required maxlength="60" class="{{ $input }}" placeholder="Biryani">
            </div>

            <div>
                <label for="cuisine_slug" class="{{ $label }}">Slug</label>
                <input id="cuisine_slug" name="slug" required maxlength="40" pattern="[a-z0-9\-]+"
                       class="{{ $input }}" placeholder="biryani">
                <p class="mt-1 text-xs text-gray-600">Lowercase, hyphens.</p>
            </div>

            <div>
                <label for="cuisine_image" class="{{ $label }}">Icon (optional)</label>
                <input id="cuisine_image" name="image" type="file" accept="image/*" class="{{ $input }}">
            </div>

            <div class="flex items-end">
                <button class="{{ $primary }}">Add cuisine</button>
            </div>
        </form>
    </div>

    {{-- Collections -------------------------------------------------------- --}}
    <div class="{{ $card }}">
        <h2 class="font-bold text-white">Collections</h2>
        <p class="mt-1 text-sm text-gray-500">
            Curated lists such as "Meals under ₹250". Restaurants are chosen by
            hand — a list built from a query fills up with the cheapest thing
            every kitchen sells.
        </p>

        @foreach ($collections as $collection)
            <div class="mt-5 pt-5 border-t border-white/10">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-white">
                            {{ $collection->title }}
                            <span class="ml-2 text-xs font-normal font-mono text-gray-600">{{ $collection->slug }}</span>
                        </p>
                        <p class="mt-0.5 text-xs text-gray-500">
                            {{ $collection->merchants->count() }} restaurants
                            &middot; {{ $collection->show_on_home ? 'tile on home' : 'not on home' }}
                            &middot; {{ $collection->is_active ? 'live' : 'switched off' }}
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <form method="POST" action="{{ route('admin.merchandising.collections.toggle', $collection) }}">
                            @csrf
                            <button class="text-xs font-semibold text-gray-300 hover:text-white">
                                {{ $collection->is_active ? 'Switch off' : 'Switch on' }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.merchandising.collections.destroy', $collection) }}"
                              onsubmit="return confirm('Remove {{ $collection->title }}?')">
                            @csrf @method('DELETE')
                            <button class="{{ $danger }}">Remove</button>
                        </form>
                    </div>
                </div>

                <form method="POST" action="{{ route('admin.merchandising.collections.merchants', $collection) }}"
                      class="mt-3 flex flex-wrap items-end gap-3">
                    @csrf
                    <div class="flex-1 min-w-[16rem]">
                        <label for="merchants-{{ $collection->id }}" class="{{ $label }}">
                            Restaurants in this collection
                        </label>
                        <select id="merchants-{{ $collection->id }}" name="merchant_ids[]" multiple size="5"
                                class="{{ $input }}">
                            @foreach ($restaurants as $restaurant)
                                <option value="{{ $restaurant->id }}" class="bg-black"
                                    @selected($collection->merchants->contains('id', $restaurant->id))>
                                    {{ $restaurant->business_name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-600">
                            Ctrl or Cmd to pick more than one. They appear in the order listed here.
                        </p>
                    </div>
                    <button class="{{ $primary }}">Save</button>
                </form>
            </div>
        @endforeach

        <form method="POST" action="{{ route('admin.merchandising.collections.store') }}"
              enctype="multipart/form-data" class="mt-6 pt-5 border-t border-white/10 grid sm:grid-cols-3 gap-4">
            @csrf

            <div>
                <label for="collection_title" class="{{ $label }}">Title</label>
                <input id="collection_title" name="title" required maxlength="80" class="{{ $input }}"
                       placeholder="Meals under ₹250">
            </div>

            <div>
                <label for="collection_slug" class="{{ $label }}">Slug</label>
                <input id="collection_slug" name="slug" required maxlength="60" pattern="[a-z0-9\-]+"
                       class="{{ $input }}" placeholder="under-250">
            </div>

            <div>
                <label for="collection_subtitle" class="{{ $label }}">Subtitle (optional)</label>
                <input id="collection_subtitle" name="subtitle" maxlength="160" class="{{ $input }}"
                       placeholder="Full meals, nothing over ₹250">
            </div>

            <div>
                <label for="collection_image" class="{{ $label }}">Tile image (optional)</label>
                <input id="collection_image" name="image" type="file" accept="image/*" class="{{ $input }}">
            </div>

            <div>
                <label for="collection_position" class="{{ $label }}">Position</label>
                <input id="collection_position" name="position" type="number" min="0" value="0" class="{{ $input }}">
            </div>

            <div class="flex items-end">
                <button class="{{ $primary }}">Create collection</button>
            </div>
        </form>
    </div>

</section>

@endsection
