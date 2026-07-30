@extends('layouts.site')

@section('title', 'Delivery Partners')
@section('description', 'Flexible hours, weekly incentives, transparent earnings and insurance support — ride with Nexmile.')

@section('content')

<section class="max-w-7xl mx-auto px-4 sm:px-6 py-16">
    <div class="max-w-3xl">
        <span class="text-xs font-bold tracking-widest uppercase text-brand-orange">Delivery Partners</span>
        <h1 class="mt-3 text-4xl font-extrabold tracking-tight text-white">Ride close to home, earn on your terms</h1>
        <p class="mt-4 text-lg text-gray-400 leading-relaxed">
            Short distances mean you spend your time delivering rather than crossing town.
            Deliveries stay within a kilometre, so you complete more orders in the same hours.
        </p>
    </div>

    <div class="mt-12 grid sm:grid-cols-2 lg:grid-cols-4 gap-5">
        @foreach (config('site.partner_benefits') as $benefit)
            <div class="rounded-2xl border border-white/10 bg-white/[0.02] p-6 flex items-start gap-3">
                <span class="text-brand-orange font-bold text-lg leading-none mt-0.5">&check;</span>
                <span class="font-semibold text-white">{{ $benefit }}</span>
            </div>
        @endforeach
    </div>

    <div class="mt-14 rounded-2xl border border-brand-green/30 bg-brand-green/5 p-8 sm:p-10">
        <div class="grid lg:grid-cols-2 gap-8 items-center">
            <div>
                <h2 class="text-2xl font-bold text-white">Become a Delivery Partner</h2>
                <p class="mt-3 text-gray-400 leading-relaxed">
                    Send us your details and our team will guide you through onboarding
                    and document verification.
                </p>
                <a href="mailto:{{ config('site.email.info') }}?subject=Delivery%20partner%20enquiry"
                   class="mt-6 inline-block px-6 py-3 rounded-lg bg-brand-green text-black font-bold hover:bg-lime-400 transition">
                    Email {{ config('site.email.info') }}
                </a>
            </div>
            <div class="rounded-xl bg-black/50 border border-white/10 p-6">
                <h3 class="font-semibold text-white">What you'll need</h3>
                <ul class="mt-4 space-y-2.5 text-sm text-gray-400">
                    @foreach ([
                        'Aadhaar and PAN',
                        'Driving licence',
                        'Vehicle registration certificate',
                        'Vehicle insurance',
                        'Bank account for payouts',
                    ] as $doc)
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
