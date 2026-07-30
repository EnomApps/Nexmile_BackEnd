<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="@yield('description', __('site.home.meta'))">
    <meta name="theme-color" content="#000000">

    <title>@yield('title', 'Nexmile') — Nexmile India Pvt. Ltd.</title>

    <link rel="icon" href="{{ asset('images/nexmile-logo.jpg') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    {{-- Noto Sans Tamil / Devanagari so Tamil and Hindi render cleanly rather
         than falling back to whatever the device happens to ship. --}}
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800|noto-sans-tamil:400,500,600,700|noto-sans-devanagari:400,500,600,700&display=swap" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <style type="text/tailwindcss">
        @theme {
            --font-sans: 'Figtree', 'Noto Sans Tamil', 'Noto Sans Devanagari', ui-sans-serif, system-ui, sans-serif;
            --color-brand-green: #7AC943;
            --color-brand-orange: #FF7A00;
        }
        /* Indic scripts need a little more line height than Latin. */
        html:lang(ta) body, html:lang(hi) body { line-height: 1.75; }
    </style>
</head>
<body class="antialiased font-sans bg-black text-gray-200 min-h-screen flex flex-col">

@php
    $nav = [
        'about' => __('site.nav.about'),
        'services' => __('site.nav.services'),
        'food-rescue' => __('site.nav.food_rescue'),
        'merchants' => __('site.nav.merchants'),
        'delivery-partners' => __('site.nav.delivery_partners'),
        'technology' => __('site.nav.technology'),
        'investors' => __('site.nav.investors'),
        'contact' => __('site.nav.contact'),
    ];
    $locales = config('site.locales');
    $current = app()->getLocale();
@endphp

<header class="sticky top-0 z-50 bg-black/90 backdrop-blur border-b border-white/10">
    <nav class="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
        <a href="{{ route('home') }}" class="text-xl font-extrabold tracking-tight shrink-0">
            <span class="text-white">Nex</span><span class="text-brand-orange">mile</span>
        </a>

        <div class="hidden xl:flex items-center gap-5 text-sm font-medium">
            @foreach ($nav as $route => $label)
                <a href="{{ route($route) }}"
                   class="hover:text-brand-green transition {{ request()->routeIs($route) ? 'text-brand-green' : 'text-gray-300' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <div class="flex items-center gap-2">
            {{-- Language switcher --}}
            <div class="hidden sm:flex items-center rounded-lg border border-white/15 overflow-hidden">
                @foreach ($locales as $code => $locale)
                    <a href="{{ route('language.switch', $code) }}"
                       title="{{ $locale['name'] }}"
                       lang="{{ $code }}"
                       class="px-2.5 py-1.5 text-xs font-semibold transition
                              {{ $current === $code ? 'bg-brand-green text-black' : 'text-gray-400 hover:text-white hover:bg-white/5' }}">
                        {{ $locale['label'] }}
                    </a>
                @endforeach
            </div>

            <button type="button" id="navToggle" aria-label="{{ __('site.nav.menu') }}" aria-expanded="false"
                    class="xl:hidden p-2 -mr-2 text-gray-300 hover:text-white">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
        </div>
    </nav>

    <div id="navMenu" class="hidden xl:hidden border-t border-white/10 bg-black">
        <div class="px-4 py-3 space-y-1">
            @foreach ($nav as $route => $label)
                <a href="{{ route($route) }}"
                   class="block px-3 py-2.5 rounded-lg text-sm font-medium hover:bg-white/5 {{ request()->routeIs($route) ? 'text-brand-green' : 'text-gray-300' }}">
                    {{ $label }}
                </a>
            @endforeach

            <div class="sm:hidden flex gap-2 pt-3 mt-2 border-t border-white/10">
                @foreach ($locales as $code => $locale)
                    <a href="{{ route('language.switch', $code) }}"
                       lang="{{ $code }}"
                       class="px-3 py-1.5 rounded-lg text-xs font-semibold
                              {{ $current === $code ? 'bg-brand-green text-black' : 'border border-white/15 text-gray-400' }}">
                        {{ $locale['label'] }}
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</header>

<main class="flex-1">
    @yield('content')
</main>

<footer class="border-t border-white/10 mt-24">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-12 grid gap-10 sm:grid-cols-2 lg:grid-cols-4 text-sm">
        <div>
            <div class="text-xl font-extrabold tracking-tight">
                <span class="text-white">Nex</span><span class="text-brand-orange">mile</span>
            </div>
            <p class="mt-2 text-xs font-semibold tracking-wider">
                <span class="text-brand-green">{{ __('site.tagline.fast') }}</span>
                <span class="text-brand-orange">{{ __('site.tagline.fresh') }}</span>
                <span class="text-gray-400">{{ __('site.tagline.trusted') }}</span>
            </p>
            <p class="mt-4 text-gray-500 leading-relaxed">
                Nexmile India Pvt. Ltd.<br>
                {{ __('site.footer.address') }}
            </p>
        </div>

        <div>
            <h3 class="font-semibold text-white mb-3">{{ __('site.footer.company') }}</h3>
            <ul class="space-y-2 text-gray-400">
                <li><a href="{{ route('about') }}" class="hover:text-brand-green">{{ __('site.footer.about') }}</a></li>
                <li><a href="{{ route('services') }}" class="hover:text-brand-green">{{ __('site.footer.services') }}</a></li>
                <li><a href="{{ route('food-rescue') }}" class="hover:text-brand-green">{{ __('site.footer.food_rescue') }}</a></li>
                <li><a href="{{ route('technology') }}" class="hover:text-brand-green">{{ __('site.footer.technology') }}</a></li>
            </ul>
        </div>

        <div>
            <h3 class="font-semibold text-white mb-3">{{ __('site.footer.partner_with_us') }}</h3>
            <ul class="space-y-2 text-gray-400">
                <li><a href="{{ route('merchants') }}" class="hover:text-brand-green">{{ __('site.footer.merchants') }}</a></li>
                <li><a href="{{ route('delivery-partners') }}" class="hover:text-brand-green">{{ __('site.footer.partners') }}</a></li>
                <li><a href="{{ route('investors') }}" class="hover:text-brand-green">{{ __('site.footer.investors') }}</a></li>
                <li><a href="{{ route('investors') }}#careers" class="hover:text-brand-green">{{ __('site.footer.careers') }}</a></li>
            </ul>
        </div>

        <div>
            <h3 class="font-semibold text-white mb-3">{{ __('site.footer.contact') }}</h3>
            <ul class="space-y-2 text-gray-400">
                @foreach (config('site.email') as $email)
                    <li><a href="mailto:{{ $email }}" class="hover:text-brand-green break-all">{{ $email }}</a></li>
                @endforeach
                <li><a href="https://{{ config('site.website') }}" class="hover:text-brand-green">{{ config('site.website') }}</a></li>
            </ul>
        </div>
    </div>

    <div class="border-t border-white/10 py-5 text-center text-xs text-gray-600">
        &copy; {{ date('Y') }} Nexmile India Pvt. Ltd. {{ __('site.footer.rights') }}
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
