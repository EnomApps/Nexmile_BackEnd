@extends('layouts.site')

@section('title', 'Technology')
@section('description', 'AI dispatch, smart rider allocation, live GPS tracking, digital wallet and fraud detection — the technology behind Nexmile.')

@section('content')

<section class="max-w-7xl mx-auto px-4 sm:px-6 py-16">
    <div class="max-w-3xl">
        <span class="text-xs font-bold tracking-widest uppercase text-brand-green">Technology</span>
        <h1 class="mt-3 text-4xl font-extrabold tracking-tight text-white">Built for speed at neighbourhood scale</h1>
        <p class="mt-4 text-lg text-gray-400 leading-relaxed">
            Delivering in 10 to 15 minutes takes more than short distances. Our platform decides
            who cooks, who rides and which route to take, continuously.
        </p>
    </div>

    <div class="mt-12 grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach (config('site.technology') as $i => $item)
            <div class="rounded-2xl border border-white/10 bg-white/[0.02] p-6">
                <div class="text-sm font-mono text-brand-orange">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</div>
                <h2 class="mt-3 font-semibold text-white">{{ $item }}</h2>
            </div>
        @endforeach
    </div>

    <div class="mt-14 rounded-2xl border border-white/10 bg-white/[0.02] p-8 text-center">
        <h2 class="text-2xl font-bold text-white">Interested in how it works?</h2>
        <p class="mt-2 text-gray-400">We're happy to talk technology with partners and investors.</p>
        <a href="{{ route('contact') }}"
           class="mt-6 inline-block px-6 py-3 rounded-lg bg-brand-green text-black font-bold hover:bg-lime-400 transition">
            Contact us
        </a>
    </div>
</section>

@endsection
