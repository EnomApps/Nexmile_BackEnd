@extends('layouts.app')

@section('title', 'About Us')

@section('content')

<section class="max-w-3xl mx-auto px-4 sm:px-6 py-16">
    <h1 class="text-4xl font-bold tracking-tight">About Nexmile</h1>
    <p class="mt-5 text-lg text-gray-600 leading-relaxed">
        Nexmile is an ultra-hyperlocal food delivery platform built for Tier-2 and Tier-3 towns
        in Tamil Nadu. We deliver from restaurants within one kilometre of you, usually in
        10 to 15 minutes.
    </p>

    <div class="mt-12 space-y-10">
        <div>
            <h2 class="text-2xl font-bold">Why one kilometre?</h2>
            <p class="mt-3 text-gray-600 leading-relaxed">
                Most delivery platforms stretch across a whole city, which means long rides, cold food
                and delivery fees that make a ₹120 meal cost ₹180. Capping the radius at a kilometre
                changes the economics entirely: riders complete more orders per hour, fuel costs fall,
                food arrives hot, and we can charge less for delivery.
            </p>
        </div>

        <div>
            <h2 class="text-2xl font-bold">Built for our neighbourhoods</h2>
            <p class="mt-3 text-gray-600 leading-relaxed">
                The app works fully in Tamil and English. Riders are people from the same few streets
                they deliver to. Restaurants are small local kitchens, not just chains. We accept UPI
                and cash, because both still matter here.
            </p>
        </div>

        <div>
            <h2 class="text-2xl font-bold">Less food wasted</h2>
            <p class="mt-3 text-gray-600 leading-relaxed">
                Restaurants can list surplus food at a discount near closing time instead of throwing
                it away. Good meals at a lower price for customers, recovered revenue for kitchens,
                and less waste overall.
            </p>
        </div>

        <div>
            <h2 class="text-2xl font-bold">Fair to riders</h2>
            <p class="mt-3 text-gray-600 leading-relaxed">
                Short distances mean riders spend their time delivering rather than commuting across
                town. Earnings, wallet balance and payouts are visible in the rider app at all times,
                with no hidden deductions.
            </p>
        </div>
    </div>

    <div class="mt-14 rounded-2xl bg-orange-50 border border-orange-100 p-8">
        <h2 class="text-xl font-bold">Want to work with us?</h2>
        <p class="mt-2 text-gray-600">
            We're onboarding restaurant partners and riders across Tamil Nadu right now.
        </p>
        <div class="mt-5 flex flex-wrap gap-3">
            <a href="{{ route('merchant.register') }}"
               class="px-5 py-2.5 rounded-lg bg-brand text-white font-semibold hover:bg-brand-dark transition">
                Register your restaurant
            </a>
            <a href="{{ route('contact') }}"
               class="px-5 py-2.5 rounded-lg border border-gray-300 font-semibold hover:border-brand hover:text-brand transition">
                Contact us
            </a>
        </div>
    </div>
</section>

@endsection
