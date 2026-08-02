@extends('layouts.site')

@section('title', __('portal.login.title'))

@section('content')

@php
    $input = 'w-full rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2.5 text-sm text-white
              placeholder-gray-600 focus:border-brand-green focus:ring-1 focus:ring-brand-green outline-none';
    $label = 'block text-sm font-medium mb-1.5 text-gray-300';
@endphp

<section class="max-w-md mx-auto px-4 sm:px-6 py-16">
    <h1 class="text-3xl font-extrabold tracking-tight text-white">{{ __('portal.login.title') }}</h1>
    <p class="mt-2 text-gray-400">{{ __('portal.login.intro') }}</p>

    <form method="POST" action="{{ route('merchants.login.submit') }}"
          class="mt-8 rounded-2xl border border-white/10 bg-white/[0.02] p-6 sm:p-8 space-y-5">
        @csrf

        <div>
            <label for="identifier" class="{{ $label }}">{{ __('portal.fields.identifier') }}</label>
            <input id="identifier" name="identifier" value="{{ old('identifier') }}" required autofocus class="{{ $input }}">
            @error('identifier') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="{{ $label }}">{{ __('portal.fields.password') }}</label>
            <input id="password" name="password" type="password" required class="{{ $input }}">
            @error('password') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-400">
            <input type="checkbox" name="remember" value="1" class="rounded border-white/20 bg-transparent text-brand-green focus:ring-brand-green">
            {{ __('portal.login.remember') }}
        </label>

        <button type="submit" class="w-full py-3 rounded-lg bg-brand-green text-black font-bold hover:bg-lime-400 transition">
            {{ __('portal.login.submit') }}
        </button>

        <p class="text-sm text-center text-gray-400">
            {{ __('portal.login.no_account') }}
            <a href="{{ route('merchants.register') }}" class="text-brand-green font-semibold hover:underline">{{ __('portal.register.title') }}</a>
        </p>
    </form>
</section>

@endsection
