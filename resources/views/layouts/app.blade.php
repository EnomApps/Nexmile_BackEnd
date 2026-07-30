<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Nexmile — 10-15 minute food delivery from restaurants within 1 km of you.">

    <title>@yield('title', 'Nexmile') — enom.express</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <style type="text/tailwindcss">
        @theme {
            --font-sans: 'Figtree', ui-sans-serif, system-ui, sans-serif;
            --color-brand: #FF6B00;
            --color-brand-dark: #E05F00;
        }
    </style>
</head>
<body class="antialiased font-sans bg-white text-gray-800">

<header class="sticky top-0 z-50 bg-white/90 backdrop-blur border-b border-gray-100">
    <nav class="max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
        <a href="{{ route('home') }}" class="flex items-center gap-2">
            <span class="w-8 h-8 rounded-lg bg-brand flex items-center justify-center text-white font-bold">N</span>
            <span class="text-lg font-bold tracking-tight">Nexmile</span>
        </a>

        <div class="hidden md:flex items-center gap-8 text-sm font-medium">
            <a href="{{ route('home') }}" class="hover:text-brand {{ request()->routeIs('home') ? 'text-brand' : '' }}">Home</a>
            <a href="{{ route('about') }}" class="hover:text-brand {{ request()->routeIs('about') ? 'text-brand' : '' }}">About Us</a>
            <a href="{{ route('contact') }}" class="hover:text-brand {{ request()->routeIs('contact') ? 'text-brand' : '' }}">Contact Us</a>
        </div>

        <div class="flex items-center gap-3">
            @auth
                <a href="{{ route('merchant.dashboard') }}" class="text-sm font-medium hover:text-brand">Dashboard</a>
                <form method="POST" action="{{ route('merchant.logout') }}">
                    @csrf
                    <button type="submit" class="text-sm font-medium text-gray-500 hover:text-brand">Log out</button>
                </form>
            @else
                <a href="{{ route('merchant.login') }}" class="text-sm font-medium hover:text-brand">Sign in</a>
                <a href="{{ route('merchant.register') }}"
                   class="text-sm font-semibold px-4 py-2 rounded-lg bg-brand text-white hover:bg-brand-dark transition">
                    Sign up
                </a>
            @endauth
        </div>
    </nav>
</header>

<main>
    @if (session('status'))
        <div class="max-w-6xl mx-auto px-4 sm:px-6 pt-6">
            <div class="rounded-lg bg-green-50 border border-green-200 text-green-800 px-4 py-3 text-sm">
                {{ session('status') }}
            </div>
        </div>
    @endif

    @yield('content')
</main>

<footer class="border-t border-gray-100 mt-20">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-10 grid gap-8 sm:grid-cols-3 text-sm">
        <div>
            <div class="flex items-center gap-2 mb-3">
                <span class="w-7 h-7 rounded-lg bg-brand flex items-center justify-center text-white font-bold text-xs">N</span>
                <span class="font-bold">Nexmile</span>
            </div>
            <p class="text-gray-500 leading-relaxed">
                Ultra-hyperlocal food delivery. Restaurants within 1 km, delivered in 10&ndash;15 minutes.
            </p>
        </div>
        <div>
            <h3 class="font-semibold mb-3">Company</h3>
            <ul class="space-y-2 text-gray-500">
                <li><a href="{{ route('about') }}" class="hover:text-brand">About Us</a></li>
                <li><a href="{{ route('contact') }}" class="hover:text-brand">Contact Us</a></li>
            </ul>
        </div>
        <div>
            <h3 class="font-semibold mb-3">Partners</h3>
            <ul class="space-y-2 text-gray-500">
                <li><a href="{{ route('merchant.register') }}" class="hover:text-brand">Register your restaurant</a></li>
                <li><a href="{{ route('merchant.login') }}" class="hover:text-brand">Merchant sign in</a></li>
            </ul>
        </div>
    </div>
    <div class="border-t border-gray-100 py-5 text-center text-xs text-gray-400">
        &copy; {{ date('Y') }} Nexmile &middot; enom.express. All rights reserved.
    </div>
</footer>

</body>
</html>
