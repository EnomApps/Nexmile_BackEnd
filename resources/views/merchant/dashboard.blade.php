@extends('layouts.app')

@section('title', 'Merchant Dashboard')

@section('content')

@php
    $badge = match ($merchant?->kyc_status?->value) {
        'verified' => 'bg-green-100 text-green-800',
        'submitted' => 'bg-blue-100 text-blue-800',
        'rejected' => 'bg-red-100 text-red-800',
        default => 'bg-amber-100 text-amber-800',
    };
@endphp

<section class="max-w-5xl mx-auto px-4 sm:px-6 py-12">

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold tracking-tight">{{ $merchant?->business_name ?? 'Your restaurant' }}</h1>
            <p class="mt-1 text-gray-600">Signed in as {{ $user->name }}</p>
        </div>
        <span class="px-3 py-1.5 rounded-full text-sm font-semibold {{ $badge }}">
            KYC {{ ucfirst($merchant?->kyc_status?->value ?? 'pending') }}
        </span>
    </div>

    @if (! $merchant?->isKycVerified())
        <div class="mt-8 rounded-xl bg-amber-50 border border-amber-200 p-5">
            <h2 class="font-semibold text-amber-900">Your account is awaiting verification</h2>
            <p class="mt-1.5 text-sm text-amber-800 leading-relaxed">
                Our team is reviewing your details. You'll be able to publish your menu and accept
                orders once verification is complete. We'll email you at
                <strong>{{ $user->email }}</strong> when it's done.
            </p>
            @if ($merchant?->kyc_rejection_reason)
                <p class="mt-3 text-sm text-red-700">
                    <strong>Action needed:</strong> {{ $merchant->kyc_rejection_reason }}
                </p>
            @endif
        </div>
    @endif

    <div class="mt-10 grid sm:grid-cols-3 gap-5">
        @foreach ([
            ['Orders today', '0'],
            ['Menu items', '0'],
            ['Accepting orders', $merchant?->is_accepting_orders ? 'Yes' : 'No'],
        ] as [$label, $value])
            <div class="border border-gray-200 rounded-xl p-5">
                <div class="text-sm text-gray-500">{{ $label }}</div>
                <div class="mt-1 text-2xl font-bold">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="mt-10 grid lg:grid-cols-2 gap-6">
        <div class="border border-gray-200 rounded-2xl p-6">
            <h2 class="font-semibold text-lg">Business details</h2>
            <dl class="mt-4 space-y-3 text-sm">
                @foreach ([
                    'Owner' => $merchant?->owner_name,
                    'Mobile' => $merchant?->business_phone,
                    'Email' => $merchant?->business_email,
                    'Address' => trim(($merchant?->address_line1 ?? '').', '.($merchant?->city ?? '').' '.($merchant?->pincode ?? ''), ', '),
                    'State' => $merchant?->state,
                ] as $label => $value)
                    <div class="flex justify-between gap-4 border-b border-gray-100 pb-2.5 last:border-0">
                        <dt class="text-gray-500">{{ $label }}</dt>
                        <dd class="font-medium text-right">{{ $value ?: '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        <div class="border border-gray-200 rounded-2xl p-6">
            <h2 class="font-semibold text-lg">Licences &amp; tax</h2>
            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex justify-between gap-4 border-b border-gray-100 pb-2.5">
                    <dt class="text-gray-500">FSSAI licence</dt>
                    <dd class="font-medium text-right">{{ $merchant?->fssai_license_no ?: '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-gray-100 pb-2.5">
                    <dt class="text-gray-500">FSSAI expiry</dt>
                    <dd class="font-medium text-right">
                        {{ $merchant?->fssai_expiry_date?->format('d M Y') ?: '—' }}
                        @if ($merchant?->fssai_license_no && ! $merchant->hasValidFssai())
                            <span class="text-red-600">(expired)</span>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-gray-100 pb-2.5">
                    <dt class="text-gray-500">GSTIN</dt>
                    <dd class="font-medium text-right">{{ $merchant?->gstin ?: '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">PAN</dt>
                    <dd class="font-medium text-right">{{ $merchant?->pan ?: '—' }}</dd>
                </div>
            </dl>
        </div>
    </div>

    <p class="mt-10 text-sm text-gray-500">
        Menu management, incoming orders and settlement reports are coming in the next release.
    </p>

</section>

@endsection
