@extends('layouts.site')

@section('title', __('site.food_rescue.title'))
@section('description', __('site.food_rescue.meta'))

@section('content')

<section class="max-w-4xl mx-auto px-4 sm:px-6 py-16">
    <span class="text-xs font-bold tracking-widest uppercase text-brand-green">{{ __('site.food_rescue.label') }}</span>
    <h1 class="mt-3 text-4xl font-extrabold tracking-tight text-white">{{ __('site.food_rescue.heading') }}</h1>

    <div class="mt-8 space-y-6 text-gray-400 leading-relaxed text-lg">
        <p>{{ __('site.food_rescue.p1') }}</p>
        <p>{{ __('site.food_rescue.p2') }}</p>
        <p>{{ __('site.food_rescue.p3') }}</p>
    </div>

    <div class="mt-12 grid sm:grid-cols-3 gap-5">
        @foreach ([
            ['🍛', __('site.food_rescue.for_customers'), __('site.food_rescue.for_customers_body')],
            ['🏪', __('site.food_rescue.for_merchants'), __('site.food_rescue.for_merchants_body')],
            ['🌱', __('site.food_rescue.for_planet'), __('site.food_rescue.for_planet_body')],
        ] as [$icon, $title, $body])
            <div class="rounded-2xl border border-white/10 bg-white/[0.02] p-6">
                <div class="text-3xl">{{ $icon }}</div>
                <h2 class="mt-4 font-semibold text-white">{{ $title }}</h2>
                <p class="mt-1.5 text-sm text-gray-400 leading-relaxed">{{ $body }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-14 rounded-2xl border border-brand-green/30 bg-brand-green/5 p-8 text-center">
        <h2 class="text-2xl font-bold text-white">{{ __('site.food_rescue.cta_title') }}</h2>
        <p class="mt-2 text-gray-400">{{ __('site.food_rescue.cta_body') }}</p>
        <a href="{{ route('merchants') }}"
           class="mt-6 inline-block px-6 py-3 rounded-lg bg-brand-green text-black font-bold hover:bg-lime-400 transition">
            {{ __('site.cta.become_merchant') }}
        </a>
    </div>
</section>

@endsection
