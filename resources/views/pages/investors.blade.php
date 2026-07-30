@extends('layouts.site')

@section('title', 'Investor Relations')
@section('description', 'Nexmile India Pvt. Ltd. is building a scalable technology platform for India\'s rapidly growing hyperlocal commerce sector.')

@section('content')

<section class="max-w-4xl mx-auto px-4 sm:px-6 py-16">
    <span class="text-xs font-bold tracking-widest uppercase text-brand-orange">Investors</span>
    <h1 class="mt-3 text-4xl font-extrabold tracking-tight text-white">Investor Relations</h1>

    <div class="mt-8 space-y-6 text-gray-400 leading-relaxed text-lg">
        <p>
            Nexmile India Pvt. Ltd. is building a scalable technology platform focused on India's
            rapidly growing hyperlocal commerce sector. Our innovative 1 km delivery model,
            sustainability initiatives, and merchant-first approach position us to capture
            opportunities in Tier 2 and Tier 3 cities before expanding nationwide.
        </p>
        <p>
            Our long-term strategy is centred on sustainable growth, operational excellence,
            technology innovation, and creating value for customers, merchants, delivery partners,
            and shareholders.
        </p>
        <p>
            We welcome strategic investors, venture capital firms, private equity partners, and
            institutional investors who share our vision of transforming neighbourhood commerce
            across India.
        </p>
    </div>

    <div class="mt-10 rounded-2xl border border-brand-orange/30 bg-brand-orange/5 p-8">
        <h2 class="text-xl font-bold text-white">Investor enquiries</h2>
        <p class="mt-2 text-gray-400">
            For decks, financials and partnership discussions, reach our investor relations team.
        </p>
        <a href="mailto:{{ config('site.email.investors') }}?subject=Investor%20enquiry"
           class="mt-5 inline-block px-6 py-3 rounded-lg bg-brand-orange text-black font-bold hover:bg-orange-400 transition">
            {{ config('site.email.investors') }}
        </a>
    </div>

    <div id="careers" class="mt-16 scroll-mt-24">
        <span class="text-xs font-bold tracking-widest uppercase text-brand-green">Careers</span>
        <h2 class="mt-3 text-3xl font-bold text-white">Join us</h2>
        <p class="mt-4 text-gray-400 leading-relaxed text-lg">
            Join us in shaping the future of hyperlocal delivery. We are looking for talented
            professionals in engineering, operations, sales, customer support, marketing, finance,
            and business development who are passionate about innovation and community impact.
        </p>

        <div class="mt-8 flex flex-wrap gap-2.5">
            @foreach ([
                'Engineering', 'Operations', 'Sales', 'Customer Support',
                'Marketing', 'Finance', 'Business Development',
            ] as $team)
                <span class="px-4 py-2 rounded-full border border-white/15 bg-white/[0.03] text-sm font-medium text-gray-300">
                    {{ $team }}
                </span>
            @endforeach
        </div>

        <a href="mailto:{{ config('site.email.info') }}?subject=Careers%20—%20application"
           class="mt-8 inline-block px-6 py-3 rounded-lg bg-brand-green text-black font-bold hover:bg-lime-400 transition">
            Send us your CV
        </a>
    </div>
</section>

@endsection
