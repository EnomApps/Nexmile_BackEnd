@extends('layouts.site')

@section('title', __('portal.storefront.title'))

@section('content')

@php
    $card = 'rounded-2xl border border-white/10 bg-white/[0.02] p-6 sm:p-8';
    $input = 'rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white
              focus:border-brand-green focus:ring-1 focus:ring-brand-green outline-none';
@endphp

<section class="max-w-4xl mx-auto px-4 sm:px-6 py-12">

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

    {{-- Logo and banner --}}
    <div class="mt-8 {{ $card }}">
        <h2 class="text-xl font-bold text-white">{{ __('portal.storefront.images') }}</h2>
        <p class="mt-1 text-sm text-gray-500">{{ __('portal.storefront.images_hint') }}</p>

        <div class="mt-6 grid sm:grid-cols-2 gap-6">
            @foreach ([
                'logo' => ['url' => $logoUrl, 'label' => __('portal.storefront.logo'), 'hint' => __('portal.storefront.logo_hint'), 'box' => 'h-24 w-24'],
                'banner' => ['url' => $bannerUrl, 'label' => __('portal.storefront.banner'), 'hint' => __('portal.storefront.banner_hint'), 'box' => 'h-24 w-full'],
            ] as $type => $meta)
                <div class="rounded-xl border border-white/10 bg-black/30 p-4">
                    <div class="font-medium text-white text-sm">{{ $meta['label'] }}</div>
                    <p class="mt-1 text-xs text-gray-600">{{ $meta['hint'] }}</p>

                    <div class="mt-3">
                        @if ($meta['url'])
                            <img src="{{ $meta['url'] }}" alt="" class="{{ $meta['box'] }} rounded-lg object-cover">
                        @else
                            <div class="{{ $meta['box'] }} rounded-lg bg-white/5 flex items-center justify-center text-xs text-gray-700">
                                {{ __('portal.storefront.none') }}
                            </div>
                        @endif
                    </div>

                    <form method="POST" action="{{ route('merchants.storefront.image.upload') }}"
                          enctype="multipart/form-data" class="mt-3 flex flex-wrap items-center gap-2">
                        @csrf
                        <input type="hidden" name="type" value="{{ $type }}">
                        <input type="file" name="file" required accept=".jpg,.jpeg,.png,.webp"
                               class="text-xs text-gray-400 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg
                                      file:border-0 file:text-xs file:font-semibold
                                      file:bg-white/10 file:text-gray-200 hover:file:bg-white/20">
                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-green text-black text-xs font-bold hover:bg-lime-400 transition">
                            {{ $meta['url'] ? __('portal.dashboard.replace') : __('portal.dashboard.upload') }}
                        </button>
                    </form>

                    @if ($meta['url'])
                        <form method="POST" action="{{ route('merchants.storefront.image.destroy', $type) }}" class="mt-2">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs text-gray-500 hover:text-red-400">
                                {{ __('portal.dashboard.remove') }}
                            </button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- The storefront carousel. One banner heads a page; it does not sell a
         place a customer has never visited. --}}
    <div class="mt-8 {{ $card }}">
        <h2 class="text-xl font-bold text-white">{{ __('portal.storefront.photos') }}</h2>
        <p class="mt-1 text-sm text-gray-500">
            {{ __('portal.storefront.photos_hint', ['limit' => $photoLimit]) }}
        </p>

        @if ($photos->isNotEmpty())
            <div class="mt-5 space-y-2">
                @foreach ($photos as $i => $photo)
                    <div class="flex flex-wrap items-center gap-4 rounded-xl border border-white/10 bg-black/20 p-3">
                        <img src="{{ $images->url($photo->image_path) }}" alt="{{ $photo->caption }}"
                             class="w-24 h-16 rounded-lg object-cover bg-white/5 shrink-0">

                        <div class="flex-1 min-w-[10rem]">
                            <p class="text-sm text-gray-200">
                                {{ $photo->caption ?: __('portal.storefront.photo_untitled') }}
                            </p>
                            @if ($i === 0)
                                {{-- The slide most people see, and the only
                                     ordering decision that really matters. --}}
                                <p class="text-xs text-brand-green">{{ __('portal.storefront.photo_first') }}</p>
                            @endif
                        </div>

                        <div class="flex items-center gap-2">
                            @foreach (['up' => '↑', 'down' => '↓'] as $direction => $arrow)
                                @php
                                    $disabled = ($direction === 'up' && $i === 0)
                                        || ($direction === 'down' && $i === $photos->count() - 1);
                                @endphp
                                <form method="POST" action="{{ route('merchants.storefront.photos.move', $photo->id) }}">
                                    @csrf
                                    <input type="hidden" name="direction" value="{{ $direction }}">
                                    <button @disabled($disabled)
                                            aria-label="{{ __('portal.storefront.photo_move_'.$direction) }}"
                                            class="w-8 h-8 rounded-lg border border-white/15 text-sm
                                                   {{ $disabled ? 'text-gray-700' : 'text-gray-300 hover:text-white hover:border-white/30' }}">
                                        {{ $arrow }}
                                    </button>
                                </form>
                            @endforeach

                            <form method="POST" action="{{ route('merchants.storefront.photos.destroy', $photo->id) }}"
                                  onsubmit="return confirm('{{ __('portal.storefront.photo_delete_confirm') }}')">
                                @csrf @method('DELETE')
                                <button class="text-xs font-semibold text-red-300 hover:text-red-200 px-1">
                                    {{ __('portal.menu.remove_photo') }}
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="mt-5 text-sm text-gray-500">{{ __('portal.storefront.photos_none') }}</p>
        @endif

        @if ($photos->count() < $photoLimit)
            <form method="POST" action="{{ route('merchants.storefront.photos.store') }}"
                  enctype="multipart/form-data" class="mt-6 pt-5 border-t border-white/10 flex flex-wrap items-end gap-4">
                @csrf

                <div class="flex-1 min-w-[12rem]">
                    <label for="photo-file" class="block text-xs font-medium mb-1.5 text-gray-400">
                        {{ __('portal.storefront.photo_file') }}
                    </label>
                    <input id="photo-file" name="file" type="file" accept=".jpg,.jpeg,.png,.webp" required
                           class="w-full rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white">
                </div>

                <div class="flex-1 min-w-[12rem]">
                    <label for="photo-caption" class="block text-xs font-medium mb-1.5 text-gray-400">
                        {{ __('portal.storefront.photo_caption') }}
                    </label>
                    <input id="photo-caption" name="caption" maxlength="120"
                           placeholder="{{ __('portal.storefront.photo_caption_example') }}"
                           class="w-full rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white
                                  focus:border-brand-green focus:ring-1 focus:ring-brand-green outline-none">
                </div>

                <button class="px-5 py-2.5 rounded-lg bg-brand-green text-black font-bold text-sm hover:bg-lime-400 transition">
                    {{ __('portal.storefront.photo_add') }}
                </button>
            </form>
        @else
            <p class="mt-6 pt-5 border-t border-white/10 text-sm text-brand-orange">
                {{ __('portal.storefront.photos_full', ['limit' => $photoLimit]) }}
            </p>
        @endif
    </div>
    {{-- How the restaurant is listed and filtered. Set by the merchant: they
         know whether their kitchen is pure veg and what two people spend. --}}
    <div class="mt-8 {{ $card }}">
        <h2 class="text-xl font-bold text-white">{{ __('portal.storefront.listing') }}</h2>
        <p class="mt-1 text-sm text-gray-500">{{ __('portal.storefront.listing_hint') }}</p>

        <form method="POST" action="{{ route('merchants.storefront.listing') }}" class="mt-6 space-y-6">
            @csrf

            <div>
                <span class="block text-xs font-medium mb-2 text-gray-400">
                    {{ __('portal.storefront.cuisines') }}
                </span>

                @if ($cuisineChoices->isEmpty())
                    <p class="text-sm text-gray-500">{{ __('portal.storefront.cuisines_none') }}</p>
                @else
                    <div class="flex flex-wrap gap-2">
                        @foreach ($cuisineChoices as $cuisine)
                            @php $checked = in_array($cuisine->slug, $merchant->cuisines ?? [], true); @endphp
                            <label class="cursor-pointer">
                                <input type="checkbox" name="cuisines[]" value="{{ $cuisine->slug }}"
                                       class="peer sr-only" @checked($checked)>
                                <span class="inline-block px-3.5 py-2 rounded-lg border text-sm transition
                                             border-white/15 text-gray-400
                                             peer-checked:border-brand-green peer-checked:text-brand-green
                                             peer-checked:bg-brand-green/10">
                                    {{ $cuisine->name }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs text-gray-600">{{ __('portal.storefront.cuisines_max_hint') }}</p>
                @endif
            </div>

            <div class="grid sm:grid-cols-2 gap-5">
                <div>
                    <label for="cost_for_two" class="block text-xs font-medium mb-1.5 text-gray-400">
                        {{ __('portal.storefront.cost_for_two') }}
                    </label>
                    <input id="cost_for_two" name="cost_for_two" type="number" min="1" max="10000"
                           value="{{ old('cost_for_two', $merchant->cost_for_two) }}"
                           class="w-full rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white
                                  focus:border-brand-green focus:ring-1 focus:ring-brand-green outline-none">
                    <p class="mt-1 text-xs text-gray-600">{{ __('portal.storefront.cost_for_two_hint') }}</p>
                </div>

                <div class="flex items-start gap-3 pt-6">
                    <input id="is_pure_veg" name="is_pure_veg" type="checkbox" value="1"
                           @checked(old('is_pure_veg', $merchant->is_pure_veg))
                           class="mt-0.5 w-4 h-4 rounded border-white/20 bg-white/[0.03] text-brand-green">
                    <label for="is_pure_veg" class="text-sm text-gray-300">
                        {{ __('portal.storefront.pure_veg') }}
                        <span class="block text-xs text-gray-600 mt-0.5">{{ __('portal.storefront.pure_veg_hint') }}</span>
                    </label>
                </div>
            </div>

            <button class="px-5 py-2.5 rounded-lg bg-brand-green text-black font-bold text-sm hover:bg-lime-400 transition">
                {{ __('portal.storefront.save') }}
            </button>
        </form>
    </div>
    {{-- Opening hours --}}
    <div class="mt-8 {{ $card }}">
        <h2 class="text-xl font-bold text-white">{{ __('portal.storefront.hours') }}</h2>
        <p class="mt-1 text-sm text-gray-500">{{ __('portal.storefront.hours_hint') }}</p>

        <form method="POST" action="{{ route('merchants.storefront.hours') }}" class="mt-6">
            @csrf

            <div class="space-y-2">
                @foreach ($days as $number => $name)
                    @php
                        $row = $hours[$number] ?? null;
                        // No schedule saved yet means always open, so the form
                        // opens with every day ticked rather than blank.
                        $isOpen = $row === null ? $hours->isEmpty() : ! $row->is_closed;
                        $opens = substr((string) ($row->opens_at ?? '09:00'), 0, 5);
                        $closes = substr((string) ($row->closes_at ?? '22:00'), 0, 5);
                    @endphp

                    <div class="rounded-xl border border-white/10 bg-black/30 p-3 flex flex-wrap items-center gap-3">
                        <label class="flex items-center gap-2 w-36 shrink-0 text-sm text-gray-300">
                            <input type="hidden" name="days[{{ $number }}][is_open]" value="0">
                            <input type="checkbox" name="days[{{ $number }}][is_open]" value="1" @checked($isOpen)
                                   class="rounded border-white/20 bg-transparent text-brand-green focus:ring-brand-green">
                            {{ __('portal.storefront.day.'.$number) }}
                        </label>

                        <div class="flex items-center gap-2 text-sm text-gray-500">
                            <label class="sr-only" for="opens-{{ $number }}">{{ __('portal.storefront.opens') }}</label>
                            <input id="opens-{{ $number }}" type="time" name="days[{{ $number }}][opens_at]"
                                   value="{{ $opens }}" class="{{ $input }}">
                            <span>&ndash;</span>
                            <label class="sr-only" for="closes-{{ $number }}">{{ __('portal.storefront.closes') }}</label>
                            <input id="closes-{{ $number }}" type="time" name="days[{{ $number }}][closes_at]"
                                   value="{{ $closes }}" class="{{ $input }}">
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="mt-4 text-xs text-gray-600">{{ __('portal.storefront.midnight_hint') }}</p>

            <button type="submit"
                    class="mt-5 px-6 py-3 rounded-lg bg-brand-orange text-black font-bold hover:bg-orange-400 transition">
                {{ __('portal.storefront.save_hours') }}
            </button>
        </form>
    </div>

</section>

@endsection
