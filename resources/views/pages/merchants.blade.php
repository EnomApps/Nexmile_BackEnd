@extends('layouts.site')

@section('title', 'For Merchants')
@section('description', 'Low commission, fast settlement, a business dashboard and growth tools — grow your local business with Nexmile.')

@section('content')

<section class="max-w-7xl mx-auto px-4 sm:px-6 py-16">
    <div class="max-w-3xl">
        <span class="text-xs font-bold tracking-widest uppercase text-brand-green">For Merchants</span>
        <h1 class="mt-3 text-4xl font-extrabold tracking-tight text-white">Grow your business in your own neighbourhood</h1>
        <p class="mt-4 text-lg text-gray-400 leading-relaxed">
            Every local business deserves the opportunity to thrive in the digital economy. Nexmile
            helps you increase sales, reduce wastage and reach more customers within a kilometre
            of your shop.
        </p>
    </div>

    <div class="mt-12 grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach (config('site.merchant_benefits') as $benefit)
            <div class="rounded-2xl border border-white/10 bg-white/[0.02] p-6 flex items-start gap-3">
                <span class="text-brand-green font-bold text-lg leading-none mt-0.5">&check;</span>
                <span class="font-semibold text-white">{{ $benefit }}</span>
            </div>
        @endforeach
    </div>

    <div class="mt-14 rounded-2xl border border-brand-orange/30 bg-brand-orange/5 p-8 sm:p-10">
        <div class="grid lg:grid-cols-2 gap-8 items-center">
            <div>
                <h2 class="text-2xl font-bold text-white">Become a Merchant</h2>
                <p class="mt-3 text-gray-400 leading-relaxed">
                    Tell us about your business and our onboarding team will get you set up —
                    usually within two working days of receiving your documents.
                </p>
                <a href="mailto:{{ config('site.email.business') }}?subject=Merchant%20onboarding%20enquiry"
                   class="mt-6 inline-block px-6 py-3 rounded-lg bg-brand-orange text-black font-bold hover:bg-orange-400 transition">
                    Email {{ config('site.email.business') }}
                </a>
            </div>
            <div class="rounded-xl bg-black/50 border border-white/10 p-6">
                <h3 class="font-semibold text-white">What you'll need</h3>
                <ul class="mt-4 space-y-2.5 text-sm text-gray-400">
                    @foreach ([
                        'Owner name, mobile number and email',
                        'Business name and full address with PIN code',
                        'FSSAI licence number and expiry date',
                        'PAN, and GSTIN if registered',
                        'Bank account details for settlements',
                    ] as $doc)
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
