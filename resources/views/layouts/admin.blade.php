<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Internal tool: keep it out of search results. --}}
    <meta name="robots" content="noindex, nofollow">

    <title>@yield('title', 'Admin') — Nexmile</title>

    <link rel="icon" href="{{ asset('images/nexmile-mark.png') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <style type="text/tailwindcss">
        @theme {
            --font-sans: 'Figtree', ui-sans-serif, system-ui, sans-serif;
            --color-brand-green: #7AC943;
            --color-brand-orange: #FF7A00;
        }
    </style>
</head>
<body class="antialiased font-sans bg-gray-950 text-gray-200 min-h-screen flex flex-col">

@auth
    <header class="border-b border-white/10 bg-black">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 h-14 flex items-center justify-between">
            <a href="{{ route('admin.index') }}" class="flex items-center gap-2.5" aria-label="Nexmile Admin">
                <img src="{{ asset('images/nexmile-wordmark.png') }}" alt="Nexmile"
                     width="631" height="128" class="h-6 w-auto">
                <span class="text-xs font-semibold tracking-widest uppercase text-gray-500 border-l border-white/15 pl-2.5">Admin</span>
            </a>
            <div class="flex items-center gap-4 text-sm">
                <nav class="flex items-center gap-4">
                    @foreach ([
                        ['route' => 'admin.dashboard', 'pattern' => 'admin.dashboard', 'label' => 'Dashboard'],
                        ['route' => 'admin.index', 'pattern' => 'admin.index', 'label' => 'Verification'],
                        ['route' => 'admin.orders.index', 'pattern' => 'admin.orders.*', 'label' => 'Orders'],
                        ['route' => 'admin.merchandising.index', 'pattern' => 'admin.merchandising.*', 'label' => 'Home screen'],
                    ] as $tab)
                        <a href="{{ route($tab['route']) }}"
                           class="font-medium transition
                                  {{ request()->routeIs($tab['pattern']) ? 'text-white' : 'text-gray-500 hover:text-gray-300' }}">
                            {{ $tab['label'] }}
                        </a>
                    @endforeach
                </nav>
                <span class="hidden sm:inline text-gray-500 border-l border-white/15 pl-4">{{ auth()->user()->email }}</span>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" class="font-medium text-gray-400 hover:text-brand-orange">Sign out</button>
                </form>
            </div>
        </div>
    </header>
@endauth

<main class="flex-1">
    @if (session('status'))
        <div class="max-w-6xl mx-auto px-4 sm:px-6 pt-6">
            <div class="rounded-lg bg-brand-green/10 border border-brand-green/30 text-brand-green px-4 py-3 text-sm">
                {{ session('status') }}
            </div>
        </div>
    @endif

    @if ($errors->any())
        <div class="max-w-6xl mx-auto px-4 sm:px-6 pt-6">
            <div class="rounded-lg bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 text-sm space-y-1">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        </div>
    @endif

    @yield('content')
</main>

</body>
</html>
