@extends('layouts.site')

@section('title', $item->exists ? __('portal.menu.edit_item') : __('portal.menu.new_item'))

@section('content')

@php
    $input = 'w-full rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2.5 text-sm text-white
              focus:border-brand-green focus:ring-1 focus:ring-brand-green outline-none';
    $label = 'block text-sm font-medium mb-1.5 text-gray-300';

    $action = $item->exists
        ? route('merchants.menu.items.update', $item->id)
        : route('merchants.menu.items.store');
@endphp

<section class="max-w-2xl mx-auto px-4 sm:px-6 py-12">

    <a href="{{ route('merchants.menu.index') }}" class="text-sm text-gray-500 hover:text-brand-green">
        &larr; {{ __('portal.menu.title') }}
    </a>

    <h1 class="mt-4 text-3xl font-extrabold tracking-tight text-white">
        {{ $item->exists ? __('portal.menu.edit_item') : __('portal.menu.new_item') }}
    </h1>

    @if ($errors->any())
        <div class="mt-6 rounded-lg bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 text-sm space-y-1">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ $action }}" enctype="multipart/form-data"
          class="mt-8 rounded-2xl border border-white/10 bg-white/[0.02] p-6 sm:p-8 space-y-6">
        @csrf
        @if ($item->exists) @method('PATCH') @endif

        <div>
            <label for="name" class="{{ $label }}">{{ __('portal.menu.item_name') }}</label>
            <input id="name" name="name" required maxlength="255"
                   value="{{ old('name', $item->name) }}" class="{{ $input }}">
        </div>

        <div>
            <label for="description" class="{{ $label }}">
                {{ __('portal.menu.description') }}
                <span class="text-gray-600 font-normal">({{ __('portal.fields.optional') }})</span>
            </label>
            <textarea id="description" name="description" rows="3" maxlength="2000"
                      class="{{ $input }}">{{ old('description', $item->description) }}</textarea>
        </div>

        <div>
            <label for="category_id" class="{{ $label }}">{{ __('portal.menu.category') }}</label>
            <select id="category_id" name="category_id" class="{{ $input }}">
                <option value="">{{ __('portal.menu.uncategorised') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}"
                        @selected((int) old('category_id', $item->category_id) === $category->id)>
                        {{ $category->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="grid sm:grid-cols-2 gap-5">
            <div>
                <label for="price" class="{{ $label }}">{{ __('portal.menu.price') }} (₹)</label>
                <input id="price" name="price" type="number" step="0.01" min="1" required
                       value="{{ old('price', $item->price) }}" class="{{ $input }}">
            </div>

            <div>
                <label for="compare_at_price" class="{{ $label }}">
                    {{ __('portal.menu.compare_at_price') }} (₹)
                    <span class="text-gray-600 font-normal">({{ __('portal.fields.optional') }})</span>
                </label>
                <input id="compare_at_price" name="compare_at_price" type="number" step="0.01" min="1"
                       value="{{ old('compare_at_price', $item->compare_at_price) }}" class="{{ $input }}">
                <p class="mt-1 text-xs text-gray-600">{{ __('portal.menu.compare_at_price_hint') }}</p>
            </div>

            <div>
                <label for="gst_rate" class="{{ $label }}">{{ __('portal.menu.gst_rate') }}</label>
                <select id="gst_rate" name="gst_rate" class="{{ $input }}">
                    @foreach (config('menu.gst_rates') as $rate)
                        <option value="{{ $rate }}"
                            @selected((float) old('gst_rate', $item->gst_rate) === (float) $rate)>
                            {{ (int) $rate }}%
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="prep_time_minutes" class="{{ $label }}">
                    {{ __('portal.menu.prep_time') }} ({{ __('portal.menu.minutes') }})
                </label>
                <input id="prep_time_minutes" name="prep_time_minutes" type="number" min="1" max="120"
                       value="{{ old('prep_time_minutes', $item->prep_time_minutes) }}" class="{{ $input }}">
            </div>
        </div>

        <fieldset class="space-y-3">
            {{-- Unchecked checkboxes are simply absent from the POST body, so a
                 hidden "0" is what lets a merchant turn any of these off. --}}
            <label class="flex items-center gap-2 text-sm text-gray-300">
                <input type="hidden" name="is_veg" value="0">
                <input type="checkbox" name="is_veg" value="1" @checked(old('is_veg', $item->is_veg))
                       class="rounded border-white/20 bg-transparent text-brand-green focus:ring-brand-green">
                {{ __('portal.menu.veg') }}
            </label>

            <label class="flex items-center gap-2 text-sm text-gray-300">
                <input type="hidden" name="contains_egg" value="0">
                <input type="checkbox" name="contains_egg" value="1" @checked(old('contains_egg', $item->contains_egg))
                       class="rounded border-white/20 bg-transparent text-brand-orange focus:ring-brand-orange">
                {{ __('portal.menu.contains_egg') }}
            </label>

            <label class="flex items-center gap-2 text-sm text-gray-300">
                <input type="hidden" name="is_available" value="0">
                <input type="checkbox" name="is_available" value="1" @checked(old('is_available', $item->is_available))
                       class="rounded border-white/20 bg-transparent text-brand-green focus:ring-brand-green">
                {{ __('portal.menu.available') }}
            </label>
        </fieldset>

        <div>
            <label for="image" class="{{ $label }}">{{ __('portal.menu.photo') }}</label>

            @if ($imageUrl)
                <img src="{{ $imageUrl }}" alt="" width="96" height="96"
                     class="mb-3 h-24 w-24 rounded-lg object-cover">
            @endif

            <input id="image" name="image" type="file" accept=".jpg,.jpeg,.png,.webp"
                   class="text-xs text-gray-400 file:mr-2 file:py-2 file:px-3 file:rounded-lg
                          file:border-0 file:text-xs file:font-semibold
                          file:bg-white/10 file:text-gray-200 hover:file:bg-white/20">
            <p class="mt-1 text-xs text-gray-600">{{ __('portal.menu.photo_hint') }}</p>
        </div>

        <div class="pt-2 flex items-center gap-3">
            <button type="submit"
                    class="px-6 py-3 rounded-lg bg-brand-green text-black font-bold hover:bg-lime-400 transition">
                {{ __('portal.menu.save') }}
            </button>
            <a href="{{ route('merchants.menu.index') }}" class="text-sm text-gray-500 hover:text-gray-300">
                {{ __('portal.menu.cancel') }}
            </a>
        </div>
    </form>

</section>

@endsection
