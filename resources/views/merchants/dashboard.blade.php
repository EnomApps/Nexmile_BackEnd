@extends('layouts.site')

@section('title', __('portal.dashboard.title'))

@section('content')

@php
    use App\Enums\DocumentType;

    $status = $merchant->kyc_status?->value ?? 'pending';

    [$banner, $border] = match ($status) {
        'verified'  => ['bg-brand-green/10', 'border-brand-green/40'],
        'submitted' => ['bg-blue-500/10',    'border-blue-500/40'],
        'rejected'  => ['bg-red-500/10',     'border-red-500/40'],
        default     => ['bg-brand-orange/10','border-brand-orange/40'],
    };
@endphp

<section class="max-w-5xl mx-auto px-4 sm:px-6 py-12">

    @include('merchants.partials.nav')

    {{-- Trading controls. Only meaningful once they can actually take orders,
         so an applicant still uploading documents does not see a switch that
         would refuse to move. --}}
    @if ($merchant->isKycVerified())
        @php
            $open = $merchant->isOpenNow();
            $withinHours = $merchant->isWithinOperatingHours();
        @endphp

        <div class="mt-8 rounded-2xl border {{ $open ? 'border-brand-green/40 bg-brand-green/[0.07]' : 'border-brand-orange/40 bg-brand-orange/[0.07]' }} p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-lg font-bold {{ $open ? 'text-brand-green' : 'text-brand-orange' }}">
                        {{ $open ? __('portal.storefront.open_now') : __('portal.storefront.closed_now') }}
                    </p>
                    <p class="mt-1 text-sm text-gray-400">
                        @if (! $merchant->is_accepting_orders)
                            {{ __('portal.storefront.switched_off') }}
                        @elseif (! $withinHours)
                            {{ __('portal.storefront.outside_hours') }}
                        @else
                            {{ __('portal.storefront.taking_orders') }}
                        @endif
                    </p>
                </div>

                <form method="POST" action="{{ route('merchants.accepting-orders') }}">
                    @csrf
                    <input type="hidden" name="is_accepting_orders" value="{{ $merchant->is_accepting_orders ? 0 : 1 }}">
                    <button type="submit"
                            class="px-6 py-3 rounded-lg font-bold transition
                                   {{ $merchant->is_accepting_orders
                                        ? 'border border-white/20 text-gray-300 hover:border-brand-orange/50 hover:text-brand-orange'
                                        : 'bg-brand-green text-black hover:bg-lime-400' }}">
                        {{ $merchant->is_accepting_orders
                            ? __('portal.storefront.stop_orders')
                            : __('portal.storefront.start_orders') }}
                    </button>
                </form>
            </div>

            @error('is_accepting_orders')
                <p class="mt-3 text-sm text-red-300">{{ $message }}</p>
            @enderror

            @if ($merchant->is_accepting_orders && ! $withinHours)
                <p class="mt-3 text-xs text-gray-500">
                    {{ __('portal.storefront.hours_note') }}
                    <a href="{{ route('merchants.storefront.edit') }}" class="underline hover:text-white">{{ __('portal.nav.storefront') }}</a>
                </p>
            @endif
        </div>

        {{-- Today --}}
        <div class="mt-6 grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach ([
                [__('portal.earnings.orders_today'), $today['orders'], null],
                [__('portal.earnings.payout_today'), '₹'.number_format($today['payout'], 2), __('portal.earnings.after_commission')],
                [__('portal.earnings.average_order'), '₹'.number_format($today['average_order'], 2), null],
                [__('portal.orders.live'), $liveOrders, __('portal.earnings.needs_you')],
            ] as [$label, $value, $hint])
                <div class="rounded-2xl border border-white/10 bg-white/[0.02] p-5">
                    <p class="text-xs uppercase tracking-wider text-gray-500">{{ $label }}</p>
                    <p class="mt-2 text-2xl font-extrabold text-white">{{ $value }}</p>
                    @if ($hint)
                        <p class="mt-1 text-xs text-gray-600">{{ $hint }}</p>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($liveOrders > 0)
            <a href="{{ route('merchants.orders.index') }}"
               class="mt-4 block rounded-xl border border-brand-orange/30 bg-brand-orange/10 px-4 py-3 text-sm text-brand-orange hover:bg-brand-orange/15">
                {{ trans_choice('portal.earnings.waiting', $liveOrders, ['count' => $liveOrders]) }}
            </a>
        @endif

        @if ($merchant->latitude === null || $merchant->longitude === null)
            <p class="mt-4 rounded-lg bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 text-sm">
                {{ __('portal.profile.no_location_warning') }}
                <a href="{{ route('merchants.profile.edit') }}" class="underline">{{ __('portal.nav.profile') }}</a>
            </p>
        @endif
    @endif

    {{-- KYC status --}}
    <div class="mt-8 rounded-2xl border {{ $border }} {{ $banner }} p-6">
        <div class="flex flex-wrap items-center gap-3">
            <span class="text-lg font-bold text-white">{{ __("portal.kyc.{$status}") }}</span>
        </div>
        <p class="mt-2 text-sm text-gray-300 leading-relaxed">{{ __("portal.kyc.{$status}_message") }}</p>

        @if ($status === 'rejected' && $merchant->kyc_rejection_reason)
            <p class="mt-3 text-sm text-red-300">{{ $merchant->kyc_rejection_reason }}</p>
        @endif
    </div>

    {{-- Documents --}}
    <div class="mt-8 rounded-2xl border border-white/10 bg-white/[0.02] p-6 sm:p-8">
        <h2 class="text-xl font-bold text-white">{{ __('portal.dashboard.documents') }}</h2>
        <p class="mt-1 text-sm text-gray-500">{{ __('portal.dashboard.documents_hint') }}</p>

        @error('file') <p class="mt-3 text-sm text-red-400">{{ $message }}</p> @enderror
        @error('type') <p class="mt-3 text-sm text-red-400">{{ $message }}</p> @enderror
        @error('documents') <p class="mt-3 text-sm text-red-400">{{ $message }}</p> @enderror

        <div class="mt-6 space-y-3">
            @foreach ($allowed as $type)
                @php
                    $doc = $documents[$type] ?? null;
                    $isRequired = in_array($type, $required, true);
                @endphp

                <div class="rounded-xl border border-white/10 bg-black/30 p-4 flex flex-wrap items-center gap-4 justify-between">
                    <div class="min-w-0">
                        <div class="font-medium text-white">
                            {{ DocumentType::from($type)->label() }}
                            @if ($isRequired)
                                <span class="ml-1.5 text-xs text-brand-orange">{{ __('portal.dashboard.required') }}</span>
                            @endif
                        </div>
                        <div class="mt-1 text-xs">
                            @if ($doc)
                                <span class="text-gray-400">{{ $doc->original_name }}</span>
                                <span class="mx-1.5 text-gray-700">·</span>
                                <span class="{{ $doc->status->value === 'approved' ? 'text-brand-green' : ($doc->status->value === 'rejected' ? 'text-red-400' : 'text-gray-400') }}">
                                    {{ ucfirst($doc->status->value) }}
                                </span>
                                @if ($doc->rejection_reason)
                                    <span class="block mt-1 text-red-400">{{ $doc->rejection_reason }}</span>
                                @endif
                            @else
                                <span class="text-gray-600">{{ __('portal.dashboard.not_uploaded') }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <form method="POST" action="{{ route('merchants.documents.upload') }}"
                              enctype="multipart/form-data" class="flex items-center gap-2">
                            @csrf
                            <input type="hidden" name="type" value="{{ $type }}">
                            <input type="file" name="file" required
                                   accept=".jpg,.jpeg,.png,.pdf"
                                   class="text-xs text-gray-400 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg
                                          file:border-0 file:text-xs file:font-semibold
                                          file:bg-white/10 file:text-gray-200 hover:file:bg-white/20">
                            <button type="submit"
                                    class="px-3 py-1.5 rounded-lg bg-brand-green text-black text-xs font-bold hover:bg-lime-400 transition">
                                {{ $doc ? __('portal.dashboard.replace') : __('portal.dashboard.upload') }}
                            </button>
                        </form>

                        @if ($doc && $doc->status->value !== 'approved')
                            <form method="POST" action="{{ route('merchants.documents.destroy', $doc->id) }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="px-3 py-1.5 rounded-lg border border-white/15 text-xs text-gray-400 hover:text-red-400 hover:border-red-400/40 transition">
                                    {{ __('portal.dashboard.remove') }}
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        @if (! in_array($status, ['submitted', 'verified'], true))
            <div class="mt-6 pt-6 border-t border-white/10">
                @if ($missing === [])
                    <form method="POST" action="{{ route('merchants.kyc.submit') }}">
                        @csrf
                        <button type="submit"
                                class="px-6 py-3 rounded-lg bg-brand-orange text-black font-bold hover:bg-orange-400 transition">
                            {{ __('portal.dashboard.submit_for_review') }}
                        </button>
                    </form>
                @else
                    <p class="text-sm text-gray-500">{{ __('portal.dashboard.submit_hint') }}</p>
                @endif
            </div>
        @endif
    </div>

    {{-- Business details --}}
    <div class="mt-8 rounded-2xl border border-white/10 bg-white/[0.02] p-6 sm:p-8">
        <h2 class="text-xl font-bold text-white">{{ __('portal.dashboard.business_details') }}</h2>
        <dl class="mt-5 grid sm:grid-cols-2 gap-x-8 gap-y-4 text-sm">
            @foreach ([
                __('portal.dashboard.owner') => $merchant->owner_name,
                __('portal.dashboard.mobile') => $merchant->business_phone,
                __('portal.dashboard.email') => $merchant->business_email,
                __('portal.dashboard.address') => trim(($merchant->address_line1 ?? '').', '.($merchant->city ?? '').' '.($merchant->pincode ?? ''), ', '),
            ] as $label => $value)
                <div class="flex justify-between gap-4 border-b border-white/10 pb-3">
                    <dt class="text-gray-500">{{ $label }}</dt>
                    <dd class="text-right text-gray-200 break-all">{{ $value ?: '—' }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

</section>

@endsection
