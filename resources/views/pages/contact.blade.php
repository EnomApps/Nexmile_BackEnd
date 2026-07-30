@extends('layouts.app')

@section('title', 'Contact Us')

@section('content')

<section class="max-w-5xl mx-auto px-4 sm:px-6 py-16 grid lg:grid-cols-5 gap-12">

    <div class="lg:col-span-2">
        <h1 class="text-4xl font-bold tracking-tight">Contact us</h1>
        <p class="mt-4 text-gray-600 leading-relaxed">
            Questions about partnering, an order, or joining as a rider? Send us a message and
            we'll reply within 24 hours.
        </p>

        <div class="mt-8 space-y-5 text-sm">
            <div>
                <h3 class="font-semibold text-gray-900">Restaurant partnerships</h3>
                <p class="text-gray-600 mt-1">partners@nexmile.in</p>
            </div>
            <div>
                <h3 class="font-semibold text-gray-900">Customer support</h3>
                <p class="text-gray-600 mt-1">support@nexmile.in</p>
            </div>
            <div>
                <h3 class="font-semibold text-gray-900">Office</h3>
                <p class="text-gray-600 mt-1">Madurai, Tamil Nadu, India</p>
            </div>
        </div>
    </div>

    <div class="lg:col-span-3">
        <form method="POST" action="{{ route('contact.submit') }}"
              class="bg-white border border-gray-200 rounded-2xl p-6 sm:p-8 space-y-5">
            @csrf

            <div class="grid sm:grid-cols-2 gap-5">
                <div>
                    <label for="name" class="block text-sm font-medium mb-1.5">Your name <span class="text-brand">*</span></label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" required
                           class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="phone" class="block text-sm font-medium mb-1.5">Mobile number</label>
                    <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" placeholder="9876543210"
                           class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">
                    @error('phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="email" class="block text-sm font-medium mb-1.5">Email <span class="text-brand">*</span></label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required
                       class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">
                @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="subject" class="block text-sm font-medium mb-1.5">Subject</label>
                <input type="text" id="subject" name="subject" value="{{ old('subject') }}"
                       class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">
                @error('subject') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="message" class="block text-sm font-medium mb-1.5">Message <span class="text-brand">*</span></label>
                <textarea id="message" name="message" rows="5" required
                          class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand outline-none">{{ old('message') }}</textarea>
                @error('message') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <button type="submit"
                    class="w-full py-3 rounded-lg bg-brand text-white font-semibold hover:bg-brand-dark transition">
                Send message
            </button>
        </form>
    </div>

</section>

@endsection
