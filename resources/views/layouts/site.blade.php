<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="@yield('description', __('site.home.meta'))">
    <meta name="theme-color" content="#000000">

    <title>@yield('title', 'Nexmile') — {{ config('site.company') }}</title>

    <link rel="icon" href="{{ asset('images/nexmile-mark.png') }}">
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

    /*
     * A signed-in merchant gets no marketing nav.
     *
     * "Investors" and "Technology" above a live order queue are eight
     * invitations to leave the portal mid-shift, and the way back is the
     * browser's Back button onto a page whose CSRF token has since gone stale
     * — which is a 419 on the next thing they press.
     *
     * The portal has its own tabs. This bar only has to get them home, let
     * them change language, and let them out.
     */
    $portalUser = auth()->user()?->role === \App\Enums\UserRole::Merchant ? auth()->user() : null;
@endphp

<header class="sticky top-0 z-50 bg-black/90 backdrop-blur border-b border-white/10">
    <nav class="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
        {{-- Signed in, the wordmark goes to the queue rather than the
             marketing home page — that is where they were trying to get. --}}
        <a href="{{ $portalUser ? route('merchants.dashboard') : route('home') }}"
           class="shrink-0" aria-label="Nexmile">
            <img src="{{ asset('images/nexmile-wordmark.png') }}" alt="Nexmile"
                 width="631" height="128"
                 class="h-7 sm:h-8 w-auto">
        </a>

        @unless ($portalUser)
            <div class="hidden xl:flex items-center gap-5 text-sm font-medium">
                @foreach ($nav as $route => $label)
                    <a href="{{ route($route) }}"
                       class="hover:text-brand-green transition {{ request()->routeIs($route) ? 'text-brand-green' : 'text-gray-300' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        @endunless

        <div class="flex items-center gap-2">
            {{-- Language switcher. Always shown in the portal: the mobile copy
                 lives in the marketing menu, which a signed-in merchant no
                 longer has, and a shopkeeper who reads Tamil needs it far more
                 on a phone behind the counter than on a desktop. --}}
            <div class="{{ $portalUser ? 'flex' : 'hidden sm:flex' }} items-center rounded-lg border border-white/15 overflow-hidden">
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

            @if ($portalUser)
                {{-- The way out, in the place people look for it. Without a
                     visible sign-out the only exit is closing the tab, which
                     leaves the session alive on a shared shop computer. --}}
                <form method="POST" action="{{ route('merchants.logout') }}">
                    @csrf
                    <button type="submit"
                            class="px-3 py-1.5 rounded-lg border border-white/15 text-xs font-semibold text-gray-400 hover:text-white hover:border-white/30 transition">
                        {{ __('portal.dashboard.logout') }}
                    </button>
                </form>
            @else
                <button type="button" id="navToggle" aria-label="{{ __('site.nav.menu') }}" aria-expanded="false"
                        class="xl:hidden p-2 -mr-2 text-gray-300 hover:text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
            @endif
        </div>
    </nav>

    @unless ($portalUser)
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
    @endunless
</header>

<main class="flex-1">
    @yield('content')
</main>

<footer class="border-t border-white/10 mt-24">
    {{-- Four columns of marketing under a live order queue is the same
         invitation to wander off that the top bar was. What a working
         merchant might actually want from down here is somebody to email,
         so that is what is left. --}}
    @if ($portalUser)
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-8 text-sm">
            <h3 class="font-semibold text-white">{{ __('site.footer.contact') }}</h3>
            <ul class="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-gray-400">
                @foreach (config('site.email') as $email)
                    <li><a href="mailto:{{ $email }}" class="hover:text-brand-green break-all">{{ $email }}</a></li>
                @endforeach
            </ul>
        </div>
    @else
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-12 grid gap-10 sm:grid-cols-2 lg:grid-cols-4 text-sm">
        <div>
            <img src="{{ asset('images/nexmile-wordmark.png') }}" alt="Nexmile"
                 width="631" height="128"
                 class="h-8 w-auto">
            <p class="mt-3 text-xs font-semibold tracking-wider">
                <span class="text-brand-green">{{ __('site.tagline.fast') }}</span>
                <span class="text-brand-orange">{{ __('site.tagline.fresh') }}</span>
                <span class="text-gray-400">{{ __('site.tagline.trusted') }}</span>
            </p>
            <p class="mt-4 text-gray-500 leading-relaxed">
                {{ config('site.company') }}<br>
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
    @endif

    {{-- Publicly linked on every page: a customer is entitled to read these
         before ordering, and a payment provider will not activate live
         payments until it can find them.

         Kept for a signed-in merchant too, for the same reason — "every page"
         means every page, and these are the terms they are trading under. --}}
    <div class="border-t border-white/10 py-5 flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-xs text-gray-600">
        <span>&copy; {{ date('Y') }} {{ config('site.company') }}. {{ __('site.footer.rights') }}</span>

        @foreach (['terms', 'privacy', 'refunds'] as $document)
            <a href="{{ route($document) }}" class="hover:text-brand-green">
                {{ config("legal.documents.{$document}.title") }}
            </a>
        @endforeach
    </div>
</footer>

<script>
    // Absent for a signed-in merchant, who has no marketing menu to open.
    // Without the guard this throws on every portal page, and one dead script
    // takes every later one on the page down with it.
    (function () {
        var toggle = document.getElementById('navToggle');
        var menu = document.getElementById('navMenu');

        if (toggle === null || menu === null) return;

        toggle.addEventListener('click', function () {
            var open = menu.classList.toggle('hidden') === false;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    })();
</script>

</body>
</html>
