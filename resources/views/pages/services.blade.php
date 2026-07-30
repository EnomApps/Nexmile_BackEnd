@extends('layouts.site')

@section('title', 'Our Services')
@section('description', 'Food, grocery, pharmacy, bakery, courier and more — everything from your neighbourhood, delivered within 1 km.')

@section('content')

<section class="max-w-7xl mx-auto px-4 sm:px-6 py-16">
    <span class="text-xs font-bold tracking-widest uppercase text-brand-green">Our Services</span>
    <h1 class="mt-3 text-4xl font-extrabold tracking-tight text-white">Everything from your neighbourhood</h1>
    <p class="mt-4 text-lg text-gray-400 max-w-2xl leading-relaxed">
        One app for every local need, delivered from within a kilometre of you.
    </p>

    <div class="mt-12 grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach (config('site.services') as $service)
            <div class="rounded-2xl border border-white/10 bg-white/[0.02] p-6 hover:border-brand-green/40 transition">
                <div class="text-3xl">{{ $service['icon'] }}</div>
                <h2 class="mt-4 font-semibold text-white">{{ $service['name'] }}</h2>
                <p class="mt-1.5 text-sm text-gray-400 leading-relaxed">{{ $service['blurb'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-14 rounded-2xl border border-brand-orange/30 bg-brand-orange/5 p-8 text-center">
        <h2 class="text-2xl font-bold text-white">Run a local business?</h2>
        <p class="mt-2 text-gray-400">
            List your shop on Nexmile and reach every household within a kilometre.
        </p>
        <a href="{{ route('merchants') }}"
           class="mt-6 inline-block px-6 py-3 rounded-lg bg-brand-orange text-black font-bold hover:bg-orange-400 transition">
            Become a Merchant
        </a>
    </div>
</section>

@endsection
