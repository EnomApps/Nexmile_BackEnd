@extends('layouts.admin')

@section('title', 'Sign in')

@section('content')

@php
    $input = 'w-full rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2.5 text-sm text-white
              focus:border-brand-orange focus:ring-1 focus:ring-brand-orange outline-none';
@endphp

<section class="max-w-sm mx-auto px-4 py-20">
    <div class="text-center">
        <img src="{{ asset('images/nexmile-wordmark.png') }}" alt="Nexmile"
             width="631" height="128" class="h-9 w-auto mx-auto">
        <p class="mt-2 text-xs font-semibold tracking-widest uppercase text-gray-500">Admin</p>
    </div>

    <form method="POST" action="{{ route('admin.login.submit') }}"
          class="mt-8 rounded-2xl border border-white/10 bg-white/[0.02] p-6 space-y-5">
        @csrf

        <div>
            <label for="email" class="block text-sm font-medium mb-1.5 text-gray-300">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus class="{{ $input }}">
        </div>

        <div>
            <label for="password" class="block text-sm font-medium mb-1.5 text-gray-300">Password</label>
            <input id="password" name="password" type="password" required class="{{ $input }}">
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-400">
            <input type="checkbox" name="remember" value="1" class="rounded border-white/20 bg-transparent text-brand-orange focus:ring-brand-orange">
            Keep me signed in
        </label>

        <button type="submit" class="w-full py-3 rounded-lg bg-brand-orange text-black font-bold hover:bg-orange-400 transition">
            Sign in
        </button>
    </form>
</section>

@endsection
