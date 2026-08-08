@extends('layouts.site')

@section('title', __('portal.profile.title'))

@section('content')

@php
    $input = 'w-full rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2.5 text-sm text-white
              focus:border-brand-green focus:ring-1 focus:ring-brand-green outline-none';
    $label = 'block text-sm font-medium mb-1.5 text-gray-300';
    $card = 'rounded-2xl border border-white/10 bg-white/[0.02] p-6 sm:p-8';
    $noLocation = $merchant->latitude === null || $merchant->longitude === null;
@endphp

<section class="max-w-3xl mx-auto px-4 sm:px-6 py-12">

    @include('merchants.partials.nav')

    @if (session('status'))
        <div class="mt-6 rounded-lg bg-brand-green/10 border border-brand-green/30 text-brand-green px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mt-6 rounded-lg bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 text-sm space-y-1">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    @if ($noLocation)
        <p class="mt-6 rounded-lg bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 text-sm">
            {{ __('portal.profile.no_location_warning') }}
        </p>
    @endif

    <form method="POST" action="{{ route('merchants.profile.update') }}" class="mt-8 space-y-6">
        @csrf @method('PATCH')

        <div class="{{ $card }} space-y-5">
            <h2 class="text-xl font-bold text-white">{{ __('portal.profile.business') }}</h2>

            <div>
                <label for="business_name" class="{{ $label }}">{{ __('portal.fields.business_name') }}</label>
                <input id="business_name" name="business_name" required maxlength="255"
                       value="{{ old('business_name', $merchant->business_name) }}" class="{{ $input }}">
            </div>

            <div class="grid sm:grid-cols-2 gap-5">
                <div>
                    <label for="business_phone" class="{{ $label }}">{{ __('portal.dashboard.mobile') }}</label>
                    <input id="business_phone" name="business_phone" inputmode="numeric"
                           value="{{ old('business_phone', $merchant->business_phone) }}" class="{{ $input }}">
                </div>
                <div>
                    <label for="business_email" class="{{ $label }}">{{ __('portal.fields.email') }}</label>
                    <input id="business_email" name="business_email" type="email"
                           value="{{ old('business_email', $merchant->business_email) }}" class="{{ $input }}">
                </div>
            </div>

            <div>
                <label for="description" class="{{ $label }}">
                    {{ __('portal.menu.description') }}
                    <span class="text-gray-600 font-normal">({{ __('portal.fields.optional') }})</span>
                </label>
                <textarea id="description" name="description" rows="3" maxlength="2000"
                          class="{{ $input }}">{{ old('description', $merchant->description) }}</textarea>
            </div>
        </div>

        <div class="{{ $card }} space-y-5">
            <div>
                <h2 class="text-xl font-bold text-white">{{ __('portal.profile.location') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ __('portal.profile.location_hint') }}</p>
            </div>

            <div>
                <label for="address_line1" class="{{ $label }}">{{ __('portal.fields.address_line1') }}</label>
                <input id="address_line1" name="address_line1" required maxlength="255"
                       value="{{ old('address_line1', $merchant->address_line1) }}" class="{{ $input }}">
            </div>

            <div>
                <label for="address_line2" class="{{ $label }}">
                    {{ __('portal.fields.address_line2') }}
                    <span class="text-gray-600 font-normal">({{ __('portal.fields.optional') }})</span>
                </label>
                <input id="address_line2" name="address_line2" maxlength="255"
                       value="{{ old('address_line2', $merchant->address_line2) }}" class="{{ $input }}">
            </div>

            <div class="grid sm:grid-cols-3 gap-5">
                <div>
                    <label for="city" class="{{ $label }}">{{ __('portal.fields.city') }}</label>
                    <input id="city" name="city" required value="{{ old('city', $merchant->city) }}" class="{{ $input }}">
                </div>
                <div>
                    <label for="state" class="{{ $label }}">{{ __('portal.fields.state') }}</label>
                    <input id="state" name="state" value="{{ old('state', $merchant->state) }}" class="{{ $input }}">
                </div>
                <div>
                    <label for="pincode" class="{{ $label }}">{{ __('portal.fields.pincode') }}</label>
                    <input id="pincode" name="pincode" required inputmode="numeric"
                           value="{{ old('pincode', $merchant->pincode) }}" class="{{ $input }}">
                </div>
            </div>

            <div class="grid sm:grid-cols-2 gap-5">
                <div>
                    <label for="latitude" class="{{ $label }}">{{ __('portal.profile.latitude') }}</label>
                    <input id="latitude" name="latitude" type="number" step="0.0000001" required
                           value="{{ old('latitude', $merchant->latitude) }}" class="{{ $input }}">
                </div>
                <div>
                    <label for="longitude" class="{{ $label }}">{{ __('portal.profile.longitude') }}</label>
                    <input id="longitude" name="longitude" type="number" step="0.0000001" required
                           value="{{ old('longitude', $merchant->longitude) }}" class="{{ $input }}">
                </div>
            </div>

            <p class="text-xs text-gray-600">{{ __('portal.profile.coordinates_hint') }}</p>
        </div>

        <div class="{{ $card }} space-y-5">
            <h2 class="text-xl font-bold text-white">{{ __('portal.profile.ordering') }}</h2>

            <div class="grid sm:grid-cols-3 gap-5">
                <div>
                    <label for="avg_prep_time_minutes" class="{{ $label }}">{{ __('portal.menu.prep_time') }}</label>
                    <input id="avg_prep_time_minutes" name="avg_prep_time_minutes" type="number" min="1" max="120" required
                           value="{{ old('avg_prep_time_minutes', $merchant->avg_prep_time_minutes) }}" class="{{ $input }}">
                </div>
                <div>
                    <label for="packaging_fee" class="{{ $label }}">{{ __('portal.profile.packaging_fee') }} (₹)</label>
                    <input id="packaging_fee" name="packaging_fee" type="number" step="0.01" min="0" required
                           value="{{ old('packaging_fee', (float) $merchant->packaging_fee) }}" class="{{ $input }}">
                </div>
                <div>
                    <label for="min_order_value" class="{{ $label }}">{{ __('portal.profile.min_order') }} (₹)</label>
                    <input id="min_order_value" name="min_order_value" type="number" step="0.01" min="0" required
                           value="{{ old('min_order_value', (float) $merchant->min_order_value) }}" class="{{ $input }}">
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-300">
                <input type="hidden" name="supports_pickup" value="0">
                <input type="checkbox" name="supports_pickup" value="1" @checked(old('supports_pickup', $merchant->supports_pickup))
                       class="rounded border-white/20 bg-transparent text-brand-green focus:ring-brand-green">
                {{ __('portal.profile.supports_pickup') }}
            </label>

            <p class="text-xs text-gray-600">{{ __('portal.profile.commission_note') }}</p>
        </div>

        <button type="submit" class="px-6 py-3 rounded-lg bg-brand-green text-black font-bold hover:bg-lime-400 transition">
            {{ __('portal.profile.save') }}
        </button>
    </form>

</section>

@endsection
