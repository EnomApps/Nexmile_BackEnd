<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Nexmile — 10-15 minute food delivery from restaurants within 1 km. Launching soon in Tamil Nadu.">

    <title>Nexmile — Coming Soon</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <style type="text/tailwindcss">
        @theme {
            --font-sans: 'Figtree', ui-sans-serif, system-ui, sans-serif;
            --color-brand: #FF6B00;
        }
    </style>
</head>
<body class="antialiased font-sans bg-gray-950 text-white min-h-screen flex flex-col">

<main class="flex-1 flex items-center justify-center px-6 py-16">
    <div class="max-w-xl text-center">

        <div class="flex items-center justify-center gap-3">
            <span class="w-12 h-12 rounded-xl bg-brand flex items-center justify-center text-white text-2xl font-bold">N</span>
            <span class="text-3xl font-bold tracking-tight">Nexmile</span>
        </div>

        <span class="mt-8 inline-block text-xs font-semibold tracking-widest uppercase text-brand border border-brand/40 rounded-full px-4 py-1.5">
            Coming Soon
        </span>

        <h1 class="mt-6 text-4xl sm:text-5xl font-bold leading-tight tracking-tight">
            Hot food from your street,<br>
            <span class="text-brand">in 10&ndash;15 minutes.</span>
        </h1>

        <p class="mt-6 text-lg text-gray-400 leading-relaxed">
            Ultra-hyperlocal food delivery for Tamil Nadu. We're getting restaurants,
            riders and neighbourhoods ready. Launching shortly.
        </p>

        <div class="mt-10 flex flex-wrap gap-3 justify-center">
            <a href="{{ route('merchant.register') }}"
               class="px-6 py-3 rounded-lg bg-brand text-white font-semibold hover:bg-orange-600 transition">
                Partner with us
            </a>
            <a href="{{ route('merchant.login') }}"
               class="px-6 py-3 rounded-lg border border-gray-700 font-semibold text-gray-300 hover:border-brand hover:text-brand transition">
                Merchant sign in
            </a>
        </div>

        <p class="mt-8 text-sm text-gray-500">
            Restaurant owner? Register now and be live from day one.
        </p>

    </div>
</main>

<footer class="border-t border-gray-800 py-6 text-center text-xs text-gray-600">
    &copy; {{ date('Y') }} Nexmile &middot; enom.express &middot; Madurai, Tamil Nadu
</footer>

</body>
</html>
