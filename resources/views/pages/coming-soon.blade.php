<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Nexmile — fast delivery, fresh smiles. Nearby stores and restaurants delivered in minutes. Launching soon in Tamil Nadu.">
    <meta name="theme-color" content="#000000">

    <title>Nexmile — Coming Soon</title>

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
{{-- Pure black so the logo artwork's own background blends seamlessly. --}}
<body class="antialiased font-sans bg-black text-white min-h-screen flex flex-col">

<main class="flex-1 flex items-center justify-center px-6 py-12">
    <div class="w-full max-w-lg text-center">

        <img src="{{ asset('images/nexmile-logo.jpg') }}"
             alt="Nexmile — Fast Delivery. Fresh Smiles."
             class="w-full max-w-sm sm:max-w-md mx-auto h-auto">

        <div class="mt-6">
            <span class="inline-block text-xs sm:text-sm font-bold tracking-[0.25em] uppercase px-5 py-2 rounded-full
                         text-brand-orange border border-brand-orange/40">
                Coming Soon
            </span>
        </div>

        <p class="mt-7 text-base sm:text-lg text-gray-400 leading-relaxed">
            We're getting your neighbourhood ready &mdash; nearby stores and restaurants,
            delivered in minutes. Launching shortly across Tamil Nadu.
        </p>

        <div class="mt-9 h-px w-40 mx-auto bg-gradient-to-r from-brand-green via-white/40 to-brand-orange"></div>

    </div>
</main>

<footer class="py-6 text-center text-xs text-gray-600">
    &copy; {{ date('Y') }} Nexmile &middot; Madurai, Tamil Nadu
</footer>

</body>
</html>
