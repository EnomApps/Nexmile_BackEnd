@extends('layouts.app')

@section('title', 'Food delivered in 10–15 minutes')

@section('content')

{{-- Hero --}}
<section class="max-w-6xl mx-auto px-4 sm:px-6 pt-16 pb-20 grid lg:grid-cols-2 gap-12 items-center">
    <div>
        <span class="inline-block text-xs font-semibold tracking-wide uppercase text-brand bg-orange-50 px-3 py-1 rounded-full">
            Now serving Tamil Nadu
        </span>
        <h1 class="mt-5 text-4xl sm:text-5xl font-bold leading-tight tracking-tight">
            Hot food from your street,<br>
            <span class="text-brand">in 10&ndash;15 minutes.</span>
        </h1>
        <p class="mt-5 text-lg text-gray-600 leading-relaxed">
            Nexmile only shows you restaurants within <strong>1 kilometre</strong>. Shorter distance means
            hotter food, lower delivery fees, and riders who actually know your neighbourhood.
        </p>
        <div class="mt-8 flex flex-wrap gap-3">
            <a href="{{ route('merchant.register') }}"
               class="px-6 py-3 rounded-lg bg-brand text-white font-semibold hover:bg-brand-dark transition">
                Partner with us
            </a>
            <a href="{{ route('contact') }}"
               class="px-6 py-3 rounded-lg border border-gray-300 font-semibold hover:border-brand hover:text-brand transition">
                Talk to our team
            </a>
        </div>
        <p class="mt-4 text-sm text-gray-500">
            Customer apps for Android and iOS are launching soon.
        </p>
    </div>

    <div class="bg-gradient-to-br from-orange-50 to-white border border-orange-100 rounded-2xl p-8">
        <div class="space-y-4">
            @foreach ([
                ['1 km', 'Maximum delivery radius'],
                ['10–15 min', 'Typical delivery time'],
                ['Tamil + English', 'Full app localisation'],
                ['UPI · COD · Wallet', 'Payment options'],
            ] as [$stat, $label])
                <div class="flex items-baseline justify-between border-b border-orange-100 pb-3 last:border-0">
                    <span class="text-2xl font-bold text-brand">{{ $stat }}</span>
                    <span class="text-sm text-gray-600">{{ $label }}</span>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- How it works --}}
<section class="bg-gray-50 border-y border-gray-100">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-16">
        <h2 class="text-3xl font-bold text-center">How it works</h2>
        <div class="mt-12 grid md:grid-cols-4 gap-8">
            @foreach ([
                ['Browse nearby', 'Only restaurants within 1 km of your location are shown.'],
                ['Order & pay', 'Pay by UPI, cash on delivery, or your Nexmile wallet.'],
                ['We dispatch', 'A rider in your zone is auto-assigned the moment food is ready.'],
                ['Track live', 'Follow your rider on the map with a live arrival countdown.'],
            ] as $i => [$title, $body])
                <div>
                    <div class="w-10 h-10 rounded-lg bg-brand text-white flex items-center justify-center font-bold">
                        {{ $i + 1 }}
                    </div>
                    <h3 class="mt-4 font-semibold">{{ $title }}</h3>
                    <p class="mt-2 text-sm text-gray-600 leading-relaxed">{{ $body }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- For partners --}}
<section class="max-w-6xl mx-auto px-4 sm:px-6 py-16">
    <div class="grid lg:grid-cols-2 gap-12 items-center">
        <div>
            <h2 class="text-3xl font-bold">Run a restaurant?</h2>
            <p class="mt-4 text-gray-600 leading-relaxed">
                Reach every household within a kilometre of your kitchen. No commission on your first month,
                daily settlements, and a merchant dashboard that tells you the moment an order arrives.
            </p>
            <ul class="mt-6 space-y-3">
                @foreach ([
                    'Voice and sound alerts on every new order',
                    'Manage menu, stock and operating hours yourself',
                    'GST invoices and settlement statements generated automatically',
                    'Sell surplus food at a discount instead of wasting it',
                ] as $point)
                    <li class="flex gap-3 text-sm text-gray-700">
                        <span class="text-brand font-bold">&check;</span>
                        <span>{{ $point }}</span>
                    </li>
                @endforeach
            </ul>
            <a href="{{ route('merchant.register') }}"
               class="mt-8 inline-block px-6 py-3 rounded-lg bg-brand text-white font-semibold hover:bg-brand-dark transition">
                Register your restaurant
            </a>
        </div>

        <div class="bg-gray-900 rounded-2xl p-8 text-white">
            <h3 class="font-semibold text-lg">What you'll need to sign up</h3>
            <p class="mt-2 text-sm text-gray-400">Keep these handy — it takes about five minutes.</p>
            <ul class="mt-6 space-y-3 text-sm">
                @foreach ([
                    'Owner name, mobile number and email',
                    'Restaurant name and full address with PIN code',
                    'FSSAI licence number and expiry date',
                    'PAN, and GSTIN if you are registered',
                    'Bank account details for settlements',
                ] as $doc)
                    <li class="flex gap-3">
                        <span class="text-brand">&bull;</span>
                        <span class="text-gray-300">{{ $doc }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</section>

@endsection
