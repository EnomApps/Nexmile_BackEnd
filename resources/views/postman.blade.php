<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Postman collections — Nexmile API</title>

    <link rel="icon" href="{{ asset('images/nexmile-mark.png') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <style type="text/tailwindcss">
        @theme {
            --font-sans: 'Figtree', ui-sans-serif, system-ui, sans-serif;
            --color-brand-green: #7AC943;
            --color-brand-orange: #FF7A00;
        }
    </style>
</head>
<body class="antialiased font-sans bg-gray-950 text-gray-200 min-h-screen">

<section class="max-w-3xl mx-auto px-4 sm:px-6 py-16">

    <img src="{{ asset('images/nexmile-wordmark.png') }}" alt="Nexmile"
         width="631" height="128" class="h-7 w-auto">

    <h1 class="mt-8 text-3xl font-extrabold tracking-tight text-white">Postman collections</h1>
    <p class="mt-2 text-gray-400">
        One per app. Import the one you are building — each carries its own variables,
        so don't share an environment between them.
    </p>

    <div class="mt-10 space-y-4">
        @foreach ($collections as $c)
            <a href="{{ route('postman.download', $c['app']) }}"
               class="block rounded-2xl border border-white/10 bg-white/[0.02] p-6 hover:border-brand-green/40 hover:bg-white/[0.04] transition">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2.5">
                            <span class="text-lg font-bold text-white">{{ $c['name'] }}</span>
                            <span class="rounded-md bg-white/10 px-2 py-0.5 text-xs font-semibold text-gray-400">
                                {{ $c['requests'] }} requests
                            </span>
                        </div>
                        <p class="mt-1.5 text-sm text-gray-500 max-w-lg">{{ $c['blurb'] }}</p>
                    </div>
                    <span class="shrink-0 px-4 py-2 rounded-lg bg-brand-green text-black text-sm font-bold">
                        Download
                    </span>
                </div>
            </a>
        @endforeach
    </div>

    <div class="mt-10 rounded-2xl border border-white/10 bg-white/[0.02] p-6 text-sm text-gray-400 space-y-3">
        <p>
            <span class="font-semibold text-gray-200">Each collection runs top to bottom</span>
            without editing anything — requests capture the ids the next one needs.
            You fill in two things by hand: the <code class="text-brand-orange">otp_code</code> from
            the email, and <code class="text-brand-orange">pickup_code</code> on the rider
            collection, which the merchant reads off their screen.
        </p>
        <p>
            <span class="font-semibold text-gray-200">Money is a JSON number</span> and loses its
            zero fraction — ₹430.00 arrives as <code>430</code>. Read it as
            <code class="text-brand-orange">num</code>, never <code>double</code>.
        </p>
        <p>
            <span class="font-semibold text-gray-200">Serialise token refreshes behind one lock.</span>
            Two at once are treated as a stolen token and sign the user out everywhere.
        </p>
    </div>

    <p class="mt-8 text-sm text-gray-500">
        Full endpoint reference:
        <a href="{{ url('/docs/api') }}" class="text-brand-green hover:underline">/docs/api</a>
    </p>

</section>

</body>
</html>
