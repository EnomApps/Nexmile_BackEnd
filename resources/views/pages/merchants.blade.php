@extends('layouts.site')

@section('title', __('site.merchants.title'))
@section('description', __('site.merchants.meta'))

@section('content')

<section class="max-w-7xl mx-auto px-4 sm:px-6 py-16">
    <div class="max-w-3xl">
        <span class="text-xs font-bold tracking-widest uppercase text-brand-green">{{ __('site.merchants.label') }}</span>
        <h1 class="mt-3 text-4xl font-extrabold tracking-tight text-white">{{ __('site.merchants.heading') }}</h1>
        <p class="mt-4 text-lg text-gray-400 leading-relaxed">{{ __('site.merchants.intro') }}</p>
    </div>

    <div class="mt-12 grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach (__('site.merchants.benefits') as $benefit)
            <div class="rounded-2xl border border-white/10 bg-white/[0.02] p-6 flex items-start gap-3">
                <span class="text-brand-green font-bold text-lg leading-none mt-0.5">&check;</span>
                <span class="font-semibold text-white">{{ $benefit }}</span>
            </div>
        @endforeach
    </div>

    <div class="mt-14 rounded-2xl border border-brand-orange/30 bg-brand-orange/5 p-8 sm:p-10">
        <div class="grid lg:grid-cols-2 gap-8 items-center">
            <div>
                <h2 class="text-2xl font-bold text-white">{{ __('site.cta.become_merchant') }}</h2>
                <p class="mt-3 text-gray-400 leading-relaxed">{{ __('site.merchants.cta_body') }}</p>
                <div class="mt-6 flex flex-wrap gap-3">
                    <a href="{{ route('merchants.register') }}"
                       class="px-6 py-3 rounded-lg bg-brand-orange text-black font-bold hover:bg-orange-400 transition">
                        {{ __('portal.register.title') }}
                    </a>
                    <a href="{{ route('merchants.login') }}"
                       class="px-6 py-3 rounded-lg border border-white/20 font-semibold text-gray-200 hover:border-brand-orange hover:text-brand-orange transition">
                        {{ __('portal.login.submit') }}
                    </a>
                </div>
            </div>
            <div class="rounded-xl bg-black/50 border border-white/10 p-6">
                <h3 class="font-semibold text-white">{{ __('site.cta.what_you_need') }}</h3>
                <ul class="mt-4 space-y-2.5 text-sm text-gray-400">
                    @foreach (__('site.merchants.documents') as $doc)
                        <li class="flex gap-2.5">
                            <span class="text-brand-orange">&bull;</span>
                            <span>{{ $doc }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</section>

@endsection
