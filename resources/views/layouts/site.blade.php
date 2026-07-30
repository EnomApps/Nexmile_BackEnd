<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="@yield('description', 'Nexmile India Pvt. Ltd. — India\'s next-generation ultra-hyperlocal delivery platform. Fast Delivery. Fresh Smiles. Trusted Nearby.')">
    <meta name="theme-color" content="#000000">

    <title>@yield('title', 'Nexmile') — Nexmile India Pvt. Ltd.</title>

    <link rel="icon" href="{{ asset('images/nexmile-logo.jpg') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <style type="text/tailwindcss">
        @theme {
            --font-sans: 'Figtree', ui-sans-serif, system-ui, sans-serif;
            --color-brand-green: #7AC943;
            --color-brand-orange: #FF7A00;
        }
    </style>
</head>
<body class="antialiased font-sans bg-black text-gray-200 min-h-screen flex flex-col">

@php
    $nav = [
        ['about', 'About'],
        ['services', 'Services'],
        ['food-rescue', 'Food Rescue'],
        ['merchants', 'Merchants'],
        ['delivery-partners', 'Delivery Partners'],
        ['technology', 'Technology'],
        ['investors', 'Investors'],
        ['contact', 'Contact'],
    ];
@endphp

<header class="sticky top-0 z-50 bg-black/90 backdrop-blur border-b border-white/10">
    <nav class="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
        <a href="{{ route('home') }}" class="text-xl font-extrabold tracking-tight shrink-0">
            <span class="text-white">Nex</span><span class="text-brand-orange">mile</span>
        </a>

        <div class="hidden lg:flex items-center gap-6 text-sm font-medium">
            @foreach ($nav as [$route, $label])
                <a href="{{ route($route) }}"
                   class="hover:text-brand-green transition {{ request()->routeIs($route) ? 'text-brand-green' : 'text-gray-300' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <button type="button" id="navToggle" aria-label="Menu" aria-expanded="false"
                class="lg:hidden p-2 -mr-2 text-gray-300 hover:text-white">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>
    </nav>

    <div id="navMenu" class="hidden lg:hidden border-t border-white/10 bg-black">
        <div class="px-4 py-3 space-y-1">
            @foreach ($nav as [$route, $label])
                <a href="{{ route($route) }}"
                   class="block px-3 py-2.5 rounded-lg text-sm font-medium hover:bg-white/5 {{ request()->routeIs($route) ? 'text-brand-green' : 'text-gray-300' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>
</header>

<main class="flex-1">
    @yield('content')
</main>

<footer class="border-t border-white/10 mt-24">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-12 grid gap-10 sm:grid-cols-2 lg:grid-cols-4 text-sm">
        <div class="lg:col-span-1">
            <div class="text-xl font-extrabold tracking-tight">
                <span class="text-white">Nex</span><span class="text-brand-orange">mile</span>
            </div>
            <p class="mt-2 text-xs font-semibold tracking-wider uppercase">
                <span class="text-brand-green">Fast Delivery.</span>
                <span class="text-brand-orange">Fresh Smiles.</span>
                <span class="text-gray-400">Trusted Nearby.</span>
            </p>
            <p class="mt-4 text-gray-500 leading-relaxed">
                Nexmile India Pvt. Ltd.<br>
                Corporate Office, Tamil Nadu, India
            </p>
        </div>

        <div>
            <h3 class="font-semibold text-white mb-3">Company</h3>
            <ul class="space-y-2 text-gray-400">
                <li><a href="{{ route('about') }}" class="hover:text-brand-green">About Us</a></li>
                <li><a href="{{ route('services') }}" class="hover:text-brand-green">Our Services</a></li>
                <li><a href="{{ route('food-rescue') }}" class="hover:text-brand-green">Food Rescue</a></li>
                <li><a href="{{ route('technology') }}" class="hover:text-brand-green">Technology</a></li>
            </ul>
        </div>

        <div>
            <h3 class="font-semibold text-white mb-3">Partner With Us</h3>
            <ul class="space-y-2 text-gray-400">
                <li><a href="{{ route('merchants') }}" class="hover:text-brand-green">For Merchants</a></li>
                <li><a href="{{ route('delivery-partners') }}" class="hover:text-brand-green">Delivery Partners</a></li>
                <li><a href="{{ route('investors') }}" class="hover:text-brand-green">Investor Relations</a></li>
                <li><a href="{{ route('investors') }}#careers" class="hover:text-brand-green">Careers</a></li>
            </ul>
        </div>

        <div>
            <h3 class="font-semibold text-white mb-3">Contact</h3>
            <ul class="space-y-2 text-gray-400">
                <li><a href="mailto:info@nexmile.in" class="hover:text-brand-green">info@nexmile.in</a></li>
                <li><a href="mailto:business@nexmile.in" class="hover:text-brand-green">business@nexmile.in</a></li>
                <li><a href="mailto:investors@nexmile.in" class="hover:text-brand-green">investors@nexmile.in</a></li>
                <li><a href="https://www.nexmile.in" class="hover:text-brand-green">www.nexmile.in</a></li>
            </ul>
        </div>
    </div>

    <div class="border-t border-white/10 py-5 text-center text-xs text-gray-600">
        &copy; {{ date('Y') }} Nexmile India Pvt. Ltd. All rights reserved.
    </div>
</footer>

<script>
    document.getElementById('navToggle').addEventListener('click', function () {
        var menu = document.getElementById('navMenu');
        var open = menu.classList.toggle('hidden') === false;
        this.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
</script>

</body>
</html>
