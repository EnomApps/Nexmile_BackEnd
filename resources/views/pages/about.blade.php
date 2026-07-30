@extends('layouts.site')

@section('title', __('site.about.title'))
@section('description', __('site.about.meta'))

@section('content')

<section class="max-w-4xl mx-auto px-4 sm:px-6 py-16">
    <span class="text-xs font-bold tracking-widest uppercase text-brand-green">{{ __('site.about.title') }}</span>
    <h1 class="mt-3 text-4xl font-extrabold tracking-tight text-white">{{ __('site.about.heading') }}</h1>

    <div class="mt-8 space-y-6 text-gray-400 leading-relaxed text-lg">
        <p>{{ __('site.about.p1') }}</p>
        <p>{{ __('site.about.p2') }}</p>
        <p>{{ __('site.about.p3') }}</p>
    </div>

    <blockquote class="mt-10 border-l-4 border-brand-orange pl-6 py-2">
        <p class="text-xl sm:text-2xl font-semibold text-white leading-relaxed">
            {{ __('site.about.quote') }}
        </p>
    </blockquote>

    <div class="mt-12 grid sm:grid-cols-2 gap-5">
        <div class="rounded-2xl border border-white/10 bg-white/[0.02] p-6">
            <div class="text-sm text-gray-500">{{ __('site.about.founder_label') }}</div>
            <div class="mt-1 text-xl font-bold text-white">{{ config('site.founder') }}</div>
        </div>
        <div class="rounded-2xl border border-white/10 bg-white/[0.02] p-6">
            <div class="text-sm text-gray-500">{{ __('site.about.tagline_label') }}</div>
            <div class="mt-1 text-lg font-bold">
                <span class="text-brand-green">{{ __('site.tagline.fast') }}</span>
                <span class="text-brand-orange">{{ __('site.tagline.fresh') }}</span>
                <span class="text-white">{{ __('site.tagline.trusted') }}</span>
            </div>
        </div>
    </div>

    <div class="mt-12 flex flex-wrap gap-3">
        <a href="{{ route('services') }}"
           class="px-6 py-3 rounded-lg bg-brand-green text-black font-bold hover:bg-lime-400 transition">
            {{ __('site.cta.explore_services') }}
        </a>
        <a href="{{ route('contact') }}"
           class="px-6 py-3 rounded-lg border border-white/20 font-semibold text-gray-200 hover:border-brand-green hover:text-brand-green transition">
            {{ __('site.cta.contact_us') }}
        </a>
    </div>
</section>

@endsection
