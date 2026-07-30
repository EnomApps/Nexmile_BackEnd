@extends('layouts.site')

@section('title', 'About Us')
@section('description', 'Nexmile India Pvt. Ltd. is an innovative technology company transforming neighbourhood commerce through ultra-hyperlocal delivery.')

@section('content')

<section class="max-w-4xl mx-auto px-4 sm:px-6 py-16">
    <span class="text-xs font-bold tracking-widest uppercase text-brand-green">About Us</span>
    <h1 class="mt-3 text-4xl font-extrabold tracking-tight text-white">About Nexmile India Pvt. Ltd.</h1>

    <div class="mt-8 space-y-6 text-gray-400 leading-relaxed text-lg">
        <p>
            Nexmile India Pvt. Ltd. is an innovative technology company transforming neighbourhood
            commerce through ultra-hyperlocal delivery. Our platform connects customers with nearby
            restaurants, grocery stores, pharmacies, and local businesses using an intelligent 1 km
            delivery ecosystem designed for unmatched speed, affordability, and reliability.
        </p>
        <p>
            We believe every local business deserves the opportunity to thrive in the digital economy.
            By combining advanced technology with community-focused logistics, Nexmile enables merchants
            to increase sales, reduce food wastage, and reach more customers while giving consumers a
            faster and more convenient delivery experience.
        </p>
        <p>
            At Nexmile, our vision extends beyond deliveries. We are building a sustainable ecosystem
            where customers, merchants, and delivery partners grow together.
        </p>
    </div>

    <blockquote class="mt-10 border-l-4 border-brand-orange pl-6 py-2">
        <p class="text-xl sm:text-2xl font-semibold text-white leading-relaxed">
            Every order represents trust, every delivery creates a smile, and every mile brings
            communities closer.
        </p>
    </blockquote>

    <div class="mt-12 grid sm:grid-cols-2 gap-5">
        <div class="rounded-2xl border border-white/10 bg-white/[0.02] p-6">
            <div class="text-sm text-gray-500">Founder</div>
            <div class="mt-1 text-xl font-bold text-white">{{ config('site.founder') }}</div>
        </div>
        <div class="rounded-2xl border border-white/10 bg-white/[0.02] p-6">
            <div class="text-sm text-gray-500">Our Tagline</div>
            <div class="mt-1 text-lg font-bold">
                <span class="text-brand-green">Fast Delivery.</span>
                <span class="text-brand-orange">Fresh Smiles.</span>
                <span class="text-white">Trusted Nearby.</span>
            </div>
        </div>
    </div>

    <div class="mt-12 flex flex-wrap gap-3">
        <a href="{{ route('services') }}"
           class="px-6 py-3 rounded-lg bg-brand-green text-black font-bold hover:bg-lime-400 transition">
            Explore our services
        </a>
        <a href="{{ route('contact') }}"
           class="px-6 py-3 rounded-lg border border-white/20 font-semibold text-gray-200 hover:border-brand-green hover:text-brand-green transition">
            Contact us
        </a>
    </div>
</section>

@endsection
