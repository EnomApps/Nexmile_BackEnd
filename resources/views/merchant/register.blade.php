@extends('layouts.app')

@section('title', 'Register Your Restaurant')

@section('content')

<section class="max-w-3xl mx-auto px-4 sm:px-6 py-16">
    <h1 class="text-3xl font-bold tracking-tight">Register your restaurant</h1>
    <p class="mt-2 text-gray-600">
        Takes about five minutes. Our team verifies your documents and activates the account,
        usually within two working days.
    </p>

    @if ($errors->any())
        <div class="mt-6 rounded-lg bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">
            Please correct the {{ $errors->count() }} highlighted {{ Str::plural('field', $errors->count()) }} below.
        </div>
    @endif

    <form method="POST" action="{{ route('merchant.register.submit') }}"
          class="mt-8 space-y-8">
        @csrf

        {{-- Owner account --}}
        <div class="bg-white border border-gray-200 rounded-2xl p-6 sm:p-8">
            <h2 class="font-semibold text-lg">Owner account</h2>
            <p class="text-sm text-gray-500 mt-1">You'll use these details to sign in.</p>

            <div class="mt-6 space-y-5">
                <div>
                    <label for="owner_name" class="block text-sm font-medium mb-1.5">Owner name <span class="text-brand">*</span></label>
                    <input type="text" id="owner_name" name="owner_name" value="{{ old('owner_name') }}" required
                           class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">
                    @error('owner_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid sm:grid-cols-2 gap-5">
                    <div>
                        <label for="phone" class="block text-sm font-medium mb-1.5">Mobile number <span class="text-brand">*</span></label>
                        <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" placeholder="9876543210" required
                               class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">
                        @error('phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="email" class="block text-sm font-medium mb-1.5">Email <span class="text-brand">*</span></label>
                        <input type="email" id="email" name="email" value="{{ old('email') }}" required
                               class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">
                        @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid sm:grid-cols-2 gap-5">
                    <div>
                        <label for="password" class="block text-sm font-medium mb-1.5">Password <span class="text-brand">*</span></label>
                        <input type="password" id="password" name="password" required
                               class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">
                        <p class="mt-1 text-xs text-gray-500">At least 8 characters, with letters and numbers.</p>
                        @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium mb-1.5">Confirm password <span class="text-brand">*</span></label>
                        <input type="password" id="password_confirmation" name="password_confirmation" required
                               class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">
                    </div>
                </div>

                <div>
                    <label for="preferred_locale" class="block text-sm font-medium mb-1.5">Preferred language</label>
                    <select id="preferred_locale" name="preferred_locale"
                            class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">
                        <option value="en" @selected(old('preferred_locale') === 'en')>English</option>
                        <option value="ta" @selected(old('preferred_locale') === 'ta')>தமிழ் (Tamil)</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- Restaurant --}}
        <div class="bg-white border border-gray-200 rounded-2xl p-6 sm:p-8">
            <h2 class="font-semibold text-lg">Restaurant details</h2>
            <p class="text-sm text-gray-500 mt-1">The address decides which customers can see you.</p>

            <div class="mt-6 space-y-5">
                <div>
                    <label for="business_name" class="block text-sm font-medium mb-1.5">Restaurant name <span class="text-brand">*</span></label>
                    <input type="text" id="business_name" name="business_name" value="{{ old('business_name') }}" required
                           class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">
                    @error('business_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="address_line1" class="block text-sm font-medium mb-1.5">Address line 1 <span class="text-brand">*</span></label>
                    <input type="text" id="address_line1" name="address_line1" value="{{ old('address_line1') }}" required
                           class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">
                    @error('address_line1') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="address_line2" class="block text-sm font-medium mb-1.5">Address line 2</label>
                    <input type="text" id="address_line2" name="address_line2" value="{{ old('address_line2') }}"
                           class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">
                </div>

                <div class="grid sm:grid-cols-3 gap-5">
                    <div>
                        <label for="city" class="block text-sm font-medium mb-1.5">City <span class="text-brand">*</span></label>
                        <input type="text" id="city" name="city" value="{{ old('city') }}" required
                               class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">
                        @error('city') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="state" class="block text-sm font-medium mb-1.5">State</label>
                        <input type="text" id="state" name="state" value="{{ old('state', 'Tamil Nadu') }}"
                               class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">
                    </div>
                    <div>
                        <label for="pincode" class="block text-sm font-medium mb-1.5">PIN code <span class="text-brand">*</span></label>
                        <input type="text" id="pincode" name="pincode" value="{{ old('pincode') }}" placeholder="625001" required
                               class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">
                        @error('pincode') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- KYC --}}
        <div class="bg-white border border-gray-200 rounded-2xl p-6 sm:p-8">
            <h2 class="font-semibold text-lg">Licences &amp; tax</h2>
            <p class="text-sm text-gray-500 mt-1">
                Optional now, but required before your restaurant can go live.
            </p>

            <div class="mt-6 space-y-5">
                <div class="grid sm:grid-cols-2 gap-5">
                    <div>
                        <label for="fssai_license_no" class="block text-sm font-medium mb-1.5">FSSAI licence number</label>
                        <input type="text" id="fssai_license_no" name="fssai_license_no" value="{{ old('fssai_license_no') }}" placeholder="14 digits"
                               class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">
                        @error('fssai_license_no') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="fssai_expiry_date" class="block text-sm font-medium mb-1.5">FSSAI expiry date</label>
                        <input type="date" id="fssai_expiry_date" name="fssai_expiry_date" value="{{ old('fssai_expiry_date') }}"
                               class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">
                        @error('fssai_expiry_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid sm:grid-cols-2 gap-5">
                    <div>
                        <label for="gstin" class="block text-sm font-medium mb-1.5">GSTIN</label>
                        <input type="text" id="gstin" name="gstin" value="{{ old('gstin') }}" placeholder="33ABCDE1234F1Z5"
                               class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm uppercase focus:border-brand focus:ring-1 focus:ring-brand outline-none">
                        @error('gstin') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="pan" class="block text-sm font-medium mb-1.5">PAN</label>
                        <input type="text" id="pan" name="pan" value="{{ old('pan') }}" placeholder="ABCDE1234F"
                               class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm uppercase focus:border-brand focus:ring-1 focus:ring-brand outline-none">
                        @error('pan') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </div>

        <button type="submit"
                class="w-full py-3.5 rounded-lg bg-brand text-white font-semibold hover:bg-brand-dark transition">
            Create merchant account
        </button>

        <p class="text-sm text-center text-gray-600">
            Already registered?
            <a href="{{ route('merchant.login') }}" class="text-brand font-semibold hover:underline">Sign in</a>
        </p>
    </form>
</section>

@endsection
