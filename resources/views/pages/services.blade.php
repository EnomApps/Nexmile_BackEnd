@extends('layouts.site')

@section('title', __('site.services.title'))
@section('description', __('site.services.meta'))

@section('content')

@php
    $icons = ['🍽️', '🛒', '💊', '🥬', '🥐', '💐', '🐾', '📦', '✏️', '🔌', '🧺', '♻️'];
@endphp

<section class="max-w-7xl mx-auto px-4 sm:px-6 py-16">
    <span class="text-xs font-bold tracking-widest uppercase text-brand-green">{{ __('site.services.title') }}</span>
    <h1 class="mt-3 text-4xl font-extrabold tracking-tight text-white">{{ __('site.services.heading') }}</h1>
    <p class="mt-4 text-lg text-gray-400 max-w-2xl leading-relaxed">{{ __('site.services.intro') }}</p>

    <div class="mt-12 grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach (__('site.services.items') as $i => $service)
            <div class="rounded-2xl border border-white/10 bg-white/[0.02] p-6 hover:border-brand-green/40 transition">
                <div class="text-3xl">{{ $icons[$i] ?? '📍' }}</div>
                <h2 class="mt-4 font-semibold text-white">{{ $service['name'] }}</h2>
                <p class="mt-1.5 text-sm text-gray-400 leading-relaxed">{{ $service['blurb'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-14 rounded-2xl border border-brand-orange/30 bg-brand-orange/5 p-8 text-center">
        <h2 class="text-2xl font-bold text-white">{{ __('site.services.cta_title') }}</h2>
        <p class="mt-2 text-gray-400">{{ __('site.services.cta_body') }}</p>
        <a href="{{ route('merchants') }}"
           class="mt-6 inline-block px-6 py-3 rounded-lg bg-brand-orange text-black font-bold hover:bg-orange-400 transition">
            {{ __('site.cta.become_merchant') }}
        </a>
    </div>
</section>

@endsection
