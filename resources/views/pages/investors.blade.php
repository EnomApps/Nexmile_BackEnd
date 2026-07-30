@extends('layouts.site')

@section('title', __('site.investors.title'))
@section('description', __('site.investors.meta'))

@section('content')

<section class="max-w-4xl mx-auto px-4 sm:px-6 py-16">
    <span class="text-xs font-bold tracking-widest uppercase text-brand-orange">{{ __('site.investors.label') }}</span>
    <h1 class="mt-3 text-4xl font-extrabold tracking-tight text-white">{{ __('site.investors.heading') }}</h1>

    <div class="mt-8 space-y-6 text-gray-400 leading-relaxed text-lg">
        <p>{{ __('site.investors.p1') }}</p>
        <p>{{ __('site.investors.p2') }}</p>
        <p>{{ __('site.investors.p3') }}</p>
    </div>

    <div class="mt-10 rounded-2xl border border-brand-orange/30 bg-brand-orange/5 p-8">
        <h2 class="text-xl font-bold text-white">{{ __('site.investors.enquiries_title') }}</h2>
        <p class="mt-2 text-gray-400">{{ __('site.investors.enquiries_body') }}</p>
        <a href="mailto:{{ config('site.email.investors') }}?subject=Investor%20enquiry"
           class="mt-5 inline-block px-6 py-3 rounded-lg bg-brand-orange text-black font-bold hover:bg-orange-400 transition break-all">
            {{ config('site.email.investors') }}
        </a>
    </div>

    <div id="careers" class="mt-16 scroll-mt-24">
        <span class="text-xs font-bold tracking-widest uppercase text-brand-green">{{ __('site.investors.careers_label') }}</span>
        <h2 class="mt-3 text-3xl font-bold text-white">{{ __('site.investors.careers_heading') }}</h2>
        <p class="mt-4 text-gray-400 leading-relaxed text-lg">{{ __('site.investors.careers_body') }}</p>

        <div class="mt-8 flex flex-wrap gap-2.5">
            @foreach (__('site.investors.teams') as $team)
                <span class="px-4 py-2 rounded-full border border-white/15 bg-white/[0.03] text-sm font-medium text-gray-300">
                    {{ $team }}
                </span>
            @endforeach
        </div>

        <a href="mailto:{{ config('site.email.info') }}?subject=Careers%20application"
           class="mt-8 inline-block px-6 py-3 rounded-lg bg-brand-green text-black font-bold hover:bg-lime-400 transition">
            {{ __('site.cta.send_cv') }}
        </a>
    </div>
</section>

@endsection
