@extends('layouts.app')

@section('title', 'Merchant Sign In')

@section('content')

<section class="max-w-md mx-auto px-4 sm:px-6 py-16">
    <h1 class="text-3xl font-bold tracking-tight">Merchant sign in</h1>
    <p class="mt-2 text-gray-600">Manage your menu, orders and settlements.</p>

    <form method="POST" action="{{ route('merchant.login.submit') }}"
          class="mt-8 bg-white border border-gray-200 rounded-2xl p-6 sm:p-8 space-y-5">
        @csrf

        <div>
            <label for="identifier" class="block text-sm font-medium mb-1.5">Mobile number or email</label>
            <input type="text" id="identifier" name="identifier" value="{{ old('identifier') }}" required autofocus
                   class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">
            @error('identifier') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium mb-1.5">Password</label>
            <input type="password" id="password" name="password" required
                   class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">
            @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-600">
            <input type="checkbox" name="remember" value="1" class="rounded border-gray-300 text-brand focus:ring-brand">
            Keep me signed in
        </label>

        <button type="submit"
                class="w-full py-3 rounded-lg bg-brand text-white font-semibold hover:bg-brand-dark transition">
            Sign in
        </button>

        <p class="text-sm text-center text-gray-600">
            New to Nexmile?
            <a href="{{ route('merchant.register') }}" class="text-brand font-semibold hover:underline">Register your restaurant</a>
        </p>
    </form>
</section>

@endsection
