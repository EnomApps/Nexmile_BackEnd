@extends('layouts.site')

@section('title', 'Food Rescue')
@section('description', 'Through Food Rescue, restaurants offer surplus meals at attractive discounts to nearby customers — less waste, more value.')

@section('content')

<section class="max-w-4xl mx-auto px-4 sm:px-6 py-16">
    <span class="text-xs font-bold tracking-widest uppercase text-brand-green">Sustainability</span>
    <h1 class="mt-3 text-4xl font-extrabold tracking-tight text-white">Food Rescue by Nexmile</h1>

    <div class="mt-8 space-y-6 text-gray-400 leading-relaxed text-lg">
        <p>
            Every day, thousands of perfectly good meals go unsold. Through Nexmile's Food Rescue
            initiative, restaurants can offer surplus meals during selected hours at attractive
            discounts to nearby customers within a 1 km delivery radius.
        </p>
        <p>
            Customers enjoy delicious meals at great prices, merchants recover revenue that would
            otherwise be lost, and food wastage is significantly reduced. This initiative supports
            sustainable consumption while creating a positive impact for businesses, communities,
            and the environment.
        </p>
        <p>
            Food Rescue is one of Nexmile's commitments to building a smarter and more responsible
            future for food delivery.
        </p>
    </div>

    <div class="mt-12 grid sm:grid-cols-3 gap-5">
        @foreach ([
            ['🍛', 'For customers', 'Delicious meals at great prices from restaurants nearby.'],
            ['🏪', 'For merchants', 'Recover revenue on surplus stock instead of writing it off.'],
            ['🌱', 'For the planet', 'Significantly less food wasted in your neighbourhood.'],
        ] as [$icon, $title, $body])
            <div class="rounded-2xl border border-white/10 bg-white/[0.02] p-6">
                <div class="text-3xl">{{ $icon }}</div>
                <h2 class="mt-4 font-semibold text-white">{{ $title }}</h2>
                <p class="mt-1.5 text-sm text-gray-400 leading-relaxed">{{ $body }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-14 rounded-2xl border border-brand-green/30 bg-brand-green/5 p-8 text-center">
        <h2 class="text-2xl font-bold text-white">Reduce waste, recover revenue</h2>
        <p class="mt-2 text-gray-400">
            Join Nexmile and start listing your surplus meals as Food Rescue deals.
        </p>
        <a href="{{ route('merchants') }}"
           class="mt-6 inline-block px-6 py-3 rounded-lg bg-brand-green text-black font-bold hover:bg-lime-400 transition">
            Become a Merchant
        </a>
    </div>
</section>

@endsection
