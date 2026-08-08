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
