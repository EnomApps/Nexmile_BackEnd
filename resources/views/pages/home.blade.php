@extends('layouts.site')

@section('title', "India's Next-Generation Ultra-Hyperlocal Delivery Platform")
@section('description', 'Nexmile connects you with nearby restaurants, grocery stores, pharmacies and local businesses through an intelligent 1 km delivery ecosystem.')

@section('content')

{{-- Hero --}}
<section class="relative overflow-hidden border-b border-white/10">
    <div class="absolute inset-0 bg-gradient-to-br from-brand-green/10 via-transparent to-brand-orange/10"></div>

    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 py-16 lg:py-24 grid lg:grid-cols-2 gap-12 items-center">
        <div>
            <h1 class="text-4xl sm:text-5xl font-extrabold leading-tight tracking-tight text-white">
                India's Next-Generation<br>
                <span class="text-brand-green">Ultra-Hyperlocal</span>
                <span class="text-brand-orange">Delivery Platform</span>
            </h1>

            <p class="mt-5 text-lg font-semibold tracking-wide">
                <span class="text-brand-green">Fast Delivery.</span>
                <span class="text-brand-orange">Fresh Smiles.</span>
                <span class="text-gray-300">Trusted Nearby.</span>
            </p>

            <p class="mt-5 text-gray-400 leading-relaxed max-w-xl">
                Nexmile connects customers with nearby restaurants, grocery stores, pharmacies
                and local businesses using an intelligent 1 km delivery ecosystem designed for
                unmatched speed, affordability and reliability.
            </p>

            <div class="mt-9 flex flex-wrap gap-3">
                <span class="px-6 py-3 rounded-lg bg-white/5 border border-white/15 text-gray-400 font-semibold cursor-default">
                    Download App
                    <span class="ml-1.5 text-xs text-brand-orange">Coming Soon</span>
                </span>
                <a href="{{ route('merchants') }}"
                   class="px-6 py-3 rounded-lg bg-brand-green text-black font-bold hover:bg-lime-400 transition">
                    Become a Merchant
                </a>
                <a href="{{ route('delivery-partners') }}"
                   class="px-6 py-3 rounded-lg bg-brand-orange text-black font-bold hover:bg-orange-400 transition">
                    Become a Delivery Partner
                </a>
                <a href="{{ route('investors') }}"
                   class="px-6 py-3 rounded-lg border border-white/20 font-semibold text-gray-200 hover:border-brand-green hover:text-brand-green transition">
                    Investor Relations
                </a>
            </div>
        </div>

        <div class="flex justify-center lg:justify-end">
            <img src="{{ asset('images/nexmile-logo.jpg') }}"
                 alt="Nexmile — Fast Delivery. Fresh Smiles. Trusted Nearby."
                 class="w-full max-w-sm rounded-2xl">
        </div>
    </div>
</section>

{{-- Statistics --}}
<section class="border-b border-white/10 bg-white/[0.02]">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-12 grid grid-cols-2 lg:grid-cols-4 gap-8 text-center">
        @foreach ([
            ['1 km', 'Delivery radius'],
            ['10–15 min', 'Target delivery time'],
            [count(config('site.services')), 'Service categories'],
            ['Tamil Nadu', 'Launching first'],
        ] as [$stat, $label])
            <div>
                <div class="text-3xl sm:text-4xl font-extrabold text-brand-green">{{ $stat }}</div>
                <div class="mt-1.5 text-sm text-gray-400">{{ $label }}</div>
            </div>
        @endforeach
    </div>
</section>

{{-- Featured services --}}
<section class="max-w-7xl mx-auto px-4 sm:px-6 py-16 lg:py-20">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 class="text-3xl font-bold text-white">Featured services</h2>
            <p class="mt-2 text-gray-400">Everything from your neighbourhood, in one app.</p>
        </div>
        <a href="{{ route('services') }}" class="text-sm font-semibold text-brand-green hover:underline">
            View all services &rarr;
        </a>
    </div>

    <div class="mt-10 grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach (array_slice(config('site.services'), 0, 6) as $service)
            <div class="rounded-2xl border border-white/10 bg-white/[0.02] p-6 hover:border-brand-green/40 transition">
                <div class="text-3xl">{{ $service['icon'] }}</div>
                <h3 class="mt-4 font-semibold text-white">{{ $service['name'] }}</h3>
                <p class="mt-1.5 text-sm text-gray-400 leading-relaxed">{{ $service['blurb'] }}</p>
            </div>
        @endforeach
    </div>
</section>

{{-- Food Rescue --}}
<section class="border-y border-white/10 bg-gradient-to-r from-brand-green/10 to-transparent">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-16 grid lg:grid-cols-2 gap-10 items-center">
        <div>
            <span class="text-xs font-bold tracking-widest uppercase text-brand-green">Sustainability</span>
            <h2 class="mt-3 text-3xl font-bold text-white">Food Rescue by Nexmile</h2>
            <p class="mt-4 text-gray-400 leading-relaxed">
                Every day, thousands of perfectly good meals go unsold. Restaurants can offer
                surplus meals during selected hours at attractive discounts to nearby customers
                within a 1 km delivery radius.
            </p>
            <a href="{{ route('food-rescue') }}"
               class="mt-6 inline-block px-6 py-3 rounded-lg bg-brand-green text-black font-bold hover:bg-lime-400 transition">
                Learn about Food Rescue
            </a>
        </div>
        <div class="grid sm:grid-cols-3 gap-4">
            @foreach ([
                ['Customers', 'Great meals at great prices'],
                ['Merchants', 'Recover revenue otherwise lost'],
                ['Planet', 'Significantly less food wasted'],
            ] as [$who, $what])
                <div class="rounded-xl border border-white/10 bg-black/40 p-5">
                    <div class="font-semibold text-brand-orange">{{ $who }}</div>
                    <div class="mt-1.5 text-sm text-gray-400 leading-relaxed">{{ $what }}</div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- Cities --}}
<section class="max-w-7xl mx-auto px-4 sm:px-6 py-16 lg:py-20 text-center">
    <h2 class="text-3xl font-bold text-white">Cities launching soon</h2>
    <p class="mt-2 text-gray-400">Starting in Tamil Nadu, expanding across India.</p>

    <div class="mt-8 flex flex-wrap justify-center gap-3">
        @foreach (config('site.cities') as $city)
            <span class="px-5 py-2.5 rounded-full border border-brand-green/40 bg-brand-green/10 text-brand-green font-semibold">
                {{ $city }}
            </span>
        @endforeach
        <span class="px-5 py-2.5 rounded-full border border-white/15 text-gray-400 font-medium">
            More cities announced soon
        </span>
    </div>

    <div class="mt-12">
        <a href="{{ route('contact') }}"
           class="inline-block px-7 py-3.5 rounded-lg bg-brand-orange text-black font-bold hover:bg-orange-400 transition">
            Get in touch
        </a>
    </div>
</section>

@endsection
