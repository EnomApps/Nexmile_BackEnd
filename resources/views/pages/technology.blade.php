@extends('layouts.site')

@section('title', __('site.technology.title'))
@section('description', __('site.technology.meta'))

@section('content')

<section class="max-w-7xl mx-auto px-4 sm:px-6 py-16">
    <div class="max-w-3xl">
        <span class="text-xs font-bold tracking-widest uppercase text-brand-green">{{ __('site.technology.label') }}</span>
        <h1 class="mt-3 text-4xl font-extrabold tracking-tight text-white">{{ __('site.technology.heading') }}</h1>
        <p class="mt-4 text-lg text-gray-400 leading-relaxed">{{ __('site.technology.intro') }}</p>
    </div>

    <div class="mt-12 grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach (__('site.technology.items') as $i => $item)
            <div class="rounded-2xl border border-white/10 bg-white/[0.02] p-6">
                <div class="text-sm font-mono text-brand-orange">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</div>
                <h2 class="mt-3 font-semibold text-white">{{ $item }}</h2>
            </div>
        @endforeach
    </div>

    <div class="mt-14 rounded-2xl border border-white/10 bg-white/[0.02] p-8 text-center">
        <h2 class="text-2xl font-bold text-white">{{ __('site.technology.cta_title') }}</h2>
        <p class="mt-2 text-gray-400">{{ __('site.technology.cta_body') }}</p>
        <a href="{{ route('contact') }}"
           class="mt-6 inline-block px-6 py-3 rounded-lg bg-brand-green text-black font-bold hover:bg-lime-400 transition">
            {{ __('site.cta.contact_us') }}
        </a>
    </div>
</section>

@endsection
