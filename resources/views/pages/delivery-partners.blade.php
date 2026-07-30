@extends('layouts.site')

@section('title', __('site.partners.title'))
@section('description', __('site.partners.meta'))

@section('content')

<section class="max-w-7xl mx-auto px-4 sm:px-6 py-16">
    <div class="max-w-3xl">
        <span class="text-xs font-bold tracking-widest uppercase text-brand-orange">{{ __('site.partners.label') }}</span>
        <h1 class="mt-3 text-4xl font-extrabold tracking-tight text-white">{{ __('site.partners.heading') }}</h1>
        <p class="mt-4 text-lg text-gray-400 leading-relaxed">{{ __('site.partners.intro') }}</p>
    </div>

    <div class="mt-12 grid sm:grid-cols-2 lg:grid-cols-4 gap-5">
        @foreach (__('site.partners.benefits') as $benefit)
            <div class="rounded-2xl border border-white/10 bg-white/[0.02] p-6 flex items-start gap-3">
                <span class="text-brand-orange font-bold text-lg leading-none mt-0.5">&check;</span>
                <span class="font-semibold text-white">{{ $benefit }}</span>
            </div>
        @endforeach
    </div>

    <div class="mt-14 rounded-2xl border border-brand-green/30 bg-brand-green/5 p-8 sm:p-10">
        <div class="grid lg:grid-cols-2 gap-8 items-center">
            <div>
                <h2 class="text-2xl font-bold text-white">{{ __('site.cta.become_partner') }}</h2>
                <p class="mt-3 text-gray-400 leading-relaxed">{{ __('site.partners.cta_body') }}</p>
                <a href="mailto:{{ config('site.email.info') }}?subject=Delivery%20partner%20enquiry"
                   class="mt-6 inline-block px-6 py-3 rounded-lg bg-brand-green text-black font-bold hover:bg-lime-400 transition break-all">
                    {{ config('site.email.info') }}
                </a>
            </div>
            <div class="rounded-xl bg-black/50 border border-white/10 p-6">
                <h3 class="font-semibold text-white">{{ __('site.cta.what_you_need') }}</h3>
                <ul class="mt-4 space-y-2.5 text-sm text-gray-400">
                    @foreach (__('site.partners.documents') as $doc)
                        <li class="flex gap-2.5">
                            <span class="text-brand-green">&bull;</span>
                            <span>{{ $doc }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</section>

@endsection
