@extends('layouts.site')

@section('title', __('site.home.title'))
@section('description', __('site.home.meta'))

@section('content')

@php $services = __('site.services.items'); @endphp

{{-- Hero --}}
<section class="relative overflow-hidden border-b border-white/10">
    <div class="absolute inset-0 bg-gradient-to-br from-brand-green/10 via-transparent to-brand-orange/10"></div>

    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 py-16 lg:py-24 grid lg:grid-cols-2 gap-12 items-center">
        <div>
            <h1 class="text-4xl sm:text-5xl font-extrabold leading-tight tracking-tight text-white">
                {{ __('site.home.h1_line1') }}<br>
                <span class="text-brand-green">{{ __('site.home.h1_line2') }}</span>
                <span class="text-brand-orange">{{ __('site.home.h1_line3') }}</span>
            </h1>

            <p class="mt-5 text-lg font-semibold tracking-wide">
                <span class="text-brand-green">{{ __('site.tagline.fast') }}</span>
                <span class="text-brand-orange">{{ __('site.tagline.fresh') }}</span>
                <span class="text-gray-300">{{ __('site.tagline.trusted') }}</span>
            </p>

            <p class="mt-5 text-gray-400 leading-relaxed max-w-xl">
                {{ __('site.home.intro') }}
            </p>

            <div class="mt-9 flex flex-wrap gap-3">
                <span class="px-6 py-3 rounded-lg bg-white/5 border border-white/15 text-gray-400 font-semibold cursor-default">
                    {{ __('site.cta.download_app') }}
                    <span class="ml-1.5 text-xs text-brand-orange">{{ __('site.cta.coming_soon') }}</span>
                </span>
                <a href="{{ route('merchants') }}"
                   class="px-6 py-3 rounded-lg bg-brand-green text-black font-bold hover:bg-lime-400 transition">
                    {{ __('site.cta.become_merchant') }}
                </a>
                <a href="{{ route('delivery-partners') }}"
                   class="px-6 py-3 rounded-lg bg-brand-orange text-black font-bold hover:bg-orange-400 transition">
                    {{ __('site.cta.become_partner') }}
                </a>
                <a href="{{ route('investors') }}"
                   class="px-6 py-3 rounded-lg border border-white/20 font-semibold text-gray-200 hover:border-brand-green hover:text-brand-green transition">
                    {{ __('site.cta.investor_relations') }}
                </a>
            </div>
        </div>

        <div class="flex justify-center lg:justify-end">
            <img src="{{ asset('images/nexmile-logo.jpg') }}"
                 alt="Nexmile — {{ __('site.tagline.fast') }} {{ __('site.tagline.fresh') }} {{ __('site.tagline.trusted') }}"
                 class="w-full max-w-sm rounded-2xl">
        </div>
    </div>
</section>

{{-- Statistics --}}
<section class="border-b border-white/10 bg-white/[0.02]">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-12 grid grid-cols-2 lg:grid-cols-4 gap-8 text-center">
        @foreach ([
            ['1 km', __('site.home.stat_radius')],
            ['10–15 min', __('site.home.stat_time')],
            [count($services), __('site.home.stat_categories')],
            [__('site.home.stat_state'), __('site.home.stat_launching')],
        ] as [$stat, $label])
            <div>
                <div class="text-2xl sm:text-3xl lg:text-4xl font-extrabold text-brand-green">{{ $stat }}</div>
                <div class="mt-1.5 text-sm text-gray-400">{{ $label }}</div>
            </div>
        @endforeach
    </div>
</section>

{{-- Featured services --}}
<section class="max-w-7xl mx-auto px-4 sm:px-6 py-16 lg:py-20">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 class="text-3xl font-bold text-white">{{ __('site.home.featured_title') }}</h2>
            <p class="mt-2 text-gray-400">{{ __('site.home.featured_sub') }}</p>
        </div>
        <a href="{{ route('services') }}" class="text-sm font-semibold text-brand-green hover:underline">
            {{ __('site.cta.view_all_services') }} &rarr;
        </a>
    </div>

    <div class="mt-10 grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach (array_slice($services, 0, 6) as $i => $service)
            <div class="rounded-2xl border border-white/10 bg-white/[0.02] p-6 hover:border-brand-green/40 transition">
                <div class="text-3xl">{{ ['🍽️', '🛒', '💊', '🥬', '🥐', '💐'][$i] }}</div>
                <h3 class="mt-4 font-semibold text-white">{{ $service['name'] }}</h3>
                <p class="mt-1.5 text-sm text-gray-400 leading-relaxed">{{ $service['blurb'] }}</p>
            </div>
        @endforeach
    </div>
</section>

{{-- Food Rescue --}}
<section class="border-y border-white/10 bg-gradient-to-r from-brand-green/10 to-transparent">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-16 grid lg:grid-cols-2 gap-10 items-center">
        <div>
            <span class="text-xs font-bold tracking-widest uppercase text-brand-green">{{ __('site.home.rescue_label') }}</span>
            <h2 class="mt-3 text-3xl font-bold text-white">{{ __('site.home.rescue_title') }}</h2>
            <p class="mt-4 text-gray-400 leading-relaxed">{{ __('site.home.rescue_intro') }}</p>
            <a href="{{ route('food-rescue') }}"
               class="mt-6 inline-block px-6 py-3 rounded-lg bg-brand-green text-black font-bold hover:bg-lime-400 transition">
                {{ __('site.cta.learn_food_rescue') }}
            </a>
        </div>
        <div class="grid sm:grid-cols-3 gap-4">
            @foreach ([
                [__('site.home.rescue_customers'), __('site.home.rescue_customers_body')],
                [__('site.home.rescue_merchants'), __('site.home.rescue_merchants_body')],
                [__('site.home.rescue_planet'), __('site.home.rescue_planet_body')],
            ] as [$who, $what])
                <div class="rounded-xl border border-white/10 bg-black/40 p-5">
                    <div class="font-semibold text-brand-orange">{{ $who }}</div>
                    <div class="mt-1.5 text-sm text-gray-400 leading-relaxed">{{ $what }}</div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- Cities --}}
<section class="max-w-7xl mx-auto px-4 sm:px-6 py-16 lg:py-20 text-center">
    <h2 class="text-3xl font-bold text-white">{{ __('site.home.cities_title') }}</h2>
    <p class="mt-2 text-gray-400">{{ __('site.home.cities_sub') }}</p>

    <div class="mt-8 flex flex-wrap justify-center gap-3">
        @foreach (__('site.cities') as $city)
            <span class="px-5 py-2.5 rounded-full border border-brand-green/40 bg-brand-green/10 text-brand-green font-semibold">
                {{ $city }}
            </span>
        @endforeach
        <span class="px-5 py-2.5 rounded-full border border-white/15 text-gray-400 font-medium">
            {{ __('site.home.cities_more') }}
        </span>
    </div>

    <div class="mt-12">
        <a href="{{ route('contact') }}"
           class="inline-block px-7 py-3.5 rounded-lg bg-brand-orange text-black font-bold hover:bg-orange-400 transition">
            {{ __('site.cta.get_in_touch') }}
        </a>
    </div>
</section>

@endsection
