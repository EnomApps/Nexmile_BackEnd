@extends('layouts.site')

@section('title', __('portal.register.title'))

@section('content')

@php
    $input = 'w-full rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2.5 text-sm text-white
              placeholder-gray-600 focus:border-brand-green focus:ring-1 focus:ring-brand-green outline-none';
    $label = 'block text-sm font-medium mb-1.5 text-gray-300';
    $card = 'rounded-2xl border border-white/10 bg-white/[0.02] p-6 sm:p-8';
@endphp

<section class="max-w-3xl mx-auto px-4 sm:px-6 py-16">
    <span class="text-xs font-bold tracking-widest uppercase text-brand-green">{{ __('site.nav.merchants') }}</span>
    <h1 class="mt-3 text-4xl font-extrabold tracking-tight text-white">{{ __('portal.register.title') }}</h1>
    <p class="mt-3 text-gray-400 leading-relaxed">{{ __('portal.register.intro') }}</p>

    @if ($errors->any())
        <div class="mt-6 rounded-lg bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 text-sm">
            {{ trans_choice('Please correct the :count highlighted field below.|Please correct the :count highlighted fields below.', $errors->count(), ['count' => $errors->count()]) }}
        </div>
    @endif

    <form method="POST" action="{{ route('merchants.register.submit') }}" class="mt-8 space-y-6">
        @csrf

        {{-- Owner account --}}
        <div class="{{ $card }}">
            <h2 class="font-semibold text-lg text-white">{{ __('portal.register.account') }}</h2>
            <p class="text-sm text-gray-500 mt-1">{{ __('portal.register.account_hint') }}</p>

            <div class="mt-6 space-y-5">
                <div>
                    <label for="owner_name" class="{{ $label }}">{{ __('portal.fields.owner_name') }} <span class="text-brand-orange">*</span></label>
                    <input id="owner_name" name="owner_name" value="{{ old('owner_name') }}" required class="{{ $input }}">
                    @error('owner_name') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="grid sm:grid-cols-2 gap-5">
                    <div>
                        <label for="phone" class="{{ $label }}">{{ __('portal.fields.phone') }} <span class="text-brand-orange">*</span></label>
                        <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" placeholder="9876543210" required class="{{ $input }}">
                        @error('phone') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="email" class="{{ $label }}">{{ __('portal.fields.email') }} <span class="text-brand-orange">*</span></label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required class="{{ $input }}">
                        @error('email') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid sm:grid-cols-2 gap-5">
                    <div>
                        <label for="password" class="{{ $label }}">{{ __('portal.fields.password') }} <span class="text-brand-orange">*</span></label>
                        <input id="password" name="password" type="password" required class="{{ $input }}">
                        <p class="mt-1 text-xs text-gray-500">{{ __('portal.fields.password_hint') }}</p>
                        @error('password') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="password_confirmation" class="{{ $label }}">{{ __('portal.fields.password_confirmation') }} <span class="text-brand-orange">*</span></label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required class="{{ $input }}">
                    </div>
                </div>

                <div>
                    <label for="preferred_locale" class="{{ $label }}">{{ __('portal.fields.language') }}</label>
                    <select id="preferred_locale" name="preferred_locale" class="{{ $input }}">
                        <option value="en" @selected(old('preferred_locale', app()->getLocale()) === 'en')>English</option>
                        <option value="ta" @selected(old('preferred_locale', app()->getLocale()) === 'ta')>தமிழ்</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- Business --}}
        <div class="{{ $card }}">
            <h2 class="font-semibold text-lg text-white">{{ __('portal.register.business') }}</h2>
            <p class="text-sm text-gray-500 mt-1">{{ __('portal.register.business_hint') }}</p>

            <div class="mt-6 space-y-5">
                <div>
                    <label for="business_name" class="{{ $label }}">{{ __('portal.fields.business_name') }} <span class="text-brand-orange">*</span></label>
                    <input id="business_name" name="business_name" value="{{ old('business_name') }}" required class="{{ $input }}">
                    @error('business_name') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="address_line1" class="{{ $label }}">{{ __('portal.fields.address_line1') }} <span class="text-brand-orange">*</span></label>
                    <input id="address_line1" name="address_line1" value="{{ old('address_line1') }}" required class="{{ $input }}">
                    @error('address_line1') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="address_line2" class="{{ $label }}">{{ __('portal.fields.address_line2') }} <span class="text-gray-600">({{ __('portal.fields.optional') }})</span></label>
                    <input id="address_line2" name="address_line2" value="{{ old('address_line2') }}" class="{{ $input }}">
                </div>

                <div class="grid sm:grid-cols-3 gap-5">
                    <div>
                        <label for="city" class="{{ $label }}">{{ __('portal.fields.city') }} <span class="text-brand-orange">*</span></label>
                        <input id="city" name="city" value="{{ old('city') }}" required class="{{ $input }}">
                        @error('city') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="state" class="{{ $label }}">{{ __('portal.fields.state') }}</label>
                        <input id="state" name="state" value="{{ old('state', 'Tamil Nadu') }}" class="{{ $input }}">
                    </div>
                    <div>
                        <label for="pincode" class="{{ $label }}">{{ __('portal.fields.pincode') }} <span class="text-brand-orange">*</span></label>
                        <input id="pincode" name="pincode" value="{{ old('pincode') }}" placeholder="625001" required class="{{ $input }}">
                        @error('pincode') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </div>

        <button type="submit"
                class="w-full py-3.5 rounded-lg bg-brand-green text-black font-bold hover:bg-lime-400 transition">
            {{ __('portal.register.submit') }}
        </button>

        <p class="text-sm text-center text-gray-400">
            {{ __('portal.register.have_account') }}
            <a href="{{ route('merchants.login') }}" class="text-brand-green font-semibold hover:underline">{{ __('portal.login.submit') }}</a>
        </p>
    </form>

    <p class="mt-8 text-sm text-gray-500 leading-relaxed">
        {{ __('portal.dashboard.next_steps') }}: {{ __('portal.kyc.pending_message') }}
    </p>
</section>

@endsection
