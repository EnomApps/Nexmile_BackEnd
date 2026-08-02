@extends('layouts.admin')

@section('title', 'Review account')

@section('content')

@php
    use App\Enums\DocumentType;

    $isMerchant = $type === 'merchants';
    $name = $isMerchant ? $owner->business_name : $owner->full_name;
    $status = $owner->kyc_status?->value ?? 'pending';

    $badge = match ($status) {
        'verified' => 'bg-brand-green/15 text-brand-green border-brand-green/40',
        'submitted' => 'bg-blue-500/15 text-blue-300 border-blue-500/40',
        'rejected' => 'bg-red-500/15 text-red-300 border-red-500/40',
        default => 'bg-white/5 text-gray-400 border-white/15',
    };

    $details = $isMerchant ? [
        'Owner' => $owner->owner_name,
        'Business phone' => $owner->business_phone,
        'Business email' => $owner->business_email,
        'Address' => trim(($owner->address_line1 ?? '').', '.($owner->city ?? '').' '.($owner->pincode ?? ''), ', '),
        'FSSAI' => $owner->fssai_license_no,
        'FSSAI expiry' => $owner->fssai_expiry_date?->format('d M Y'),
        'GSTIN' => $owner->gstin,
        'PAN' => $owner->pan,
    ] : [
        'Date of birth' => $owner->date_of_birth?->format('d M Y'),
        'Vehicle' => trim(($owner->vehicle_type ?? '').' '.($owner->vehicle_number ?? '')),
        'Licence' => $owner->driving_licence_no,
        'Licence expiry' => $owner->driving_licence_expiry?->format('d M Y'),
        'Insurance expiry' => $owner->insurance_expiry?->format('d M Y'),
        'PAN' => $owner->pan,
    ];
@endphp

<section class="max-w-5xl mx-auto px-4 sm:px-6 py-10">

    <a href="{{ route('admin.index') }}" class="text-sm text-gray-500 hover:text-white">&larr; Back to queue</a>

    <div class="mt-4 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-white">{{ $name }}</h1>
            <p class="mt-1 text-sm text-gray-500">
                {{ $owner->user?->email }} · {{ $owner->user?->phone }}
                · account {{ $owner->user?->status?->value }}
            </p>
        </div>
        <span class="px-3 py-1.5 rounded-full text-sm font-semibold border {{ $badge }}">
            {{ ucfirst($status) }}
        </span>
    </div>

    @if ($owner->kyc_rejection_reason)
        <p class="mt-4 rounded-lg bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 text-sm">
            {{ $owner->kyc_rejection_reason }}
        </p>
    @endif

    {{-- Documents --}}
    <div class="mt-8 rounded-2xl border border-white/10 bg-white/[0.02] p-6">
        <h2 class="font-semibold text-white">Documents</h2>

        @if ($documents->isEmpty())
            <p class="mt-3 text-sm text-gray-600">Nothing uploaded yet.</p>
        @else
            <div class="mt-5 space-y-3">
                @foreach ($documents as $doc)
                    @php $docType = $doc->type instanceof DocumentType ? $doc->type : DocumentType::from($doc->type); @endphp
                    <div class="rounded-xl border border-white/10 bg-black/30 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="font-medium text-white">
                                    {{ $docType->label() }}
                                    @if (in_array($docType->value, $required, true))
                                        <span class="ml-1.5 text-xs text-brand-orange">Required</span>
                                    @endif
                                </div>
                                <div class="mt-1 text-xs text-gray-500">
                                    {{ $doc->original_name }} · {{ round($doc->size_bytes / 1024) }} KB
                                    · uploaded {{ $doc->created_at?->diffForHumans() }}
                                </div>
                                @if ($doc->rejection_reason)
                                    <div class="mt-1.5 text-xs text-red-400">{{ $doc->rejection_reason }}</div>
                                @endif
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                <span class="px-2.5 py-1 rounded-lg text-xs font-semibold
                                    {{ $doc->status->value === 'approved' ? 'bg-brand-green/15 text-brand-green'
                                       : ($doc->status->value === 'rejected' ? 'bg-red-500/15 text-red-300' : 'bg-white/10 text-gray-400') }}">
                                    {{ ucfirst($doc->status->value) }}
                                </span>

                                @if ($url = $doc->temporaryUrl())
                                    {{-- Link expires in minutes; reload the page for a fresh one. --}}
                                    <a href="{{ $url }}" target="_blank" rel="noopener"
                                       class="px-3 py-1.5 rounded-lg bg-white/10 text-white text-xs font-semibold hover:bg-white/20">
                                        View
                                    </a>
                                @endif
                            </div>
                        </div>

                        <div class="mt-3 pt-3 border-t border-white/5 flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('admin.documents.review', [$type, $owner->id, $doc->id]) }}">
                                @csrf
                                <input type="hidden" name="status" value="approved">
                                <button class="px-3 py-1.5 rounded-lg bg-brand-green/20 text-brand-green text-xs font-semibold hover:bg-brand-green/30">
                                    Approve
                                </button>
                            </form>

                            <form method="POST" action="{{ route('admin.documents.review', [$type, $owner->id, $doc->id]) }}"
                                  class="flex flex-wrap gap-2 items-center">
                                @csrf
                                <input type="hidden" name="status" value="rejected">
                                <input type="text" name="rejection_reason" placeholder="Reason for rejection" required
                                       class="rounded-lg bg-white/[0.03] border border-white/15 px-3 py-1.5 text-xs text-white w-64
                                              focus:border-red-400 focus:ring-1 focus:ring-red-400 outline-none">
                                <button class="px-3 py-1.5 rounded-lg bg-red-500/20 text-red-300 text-xs font-semibold hover:bg-red-500/30">
                                    Reject
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Details --}}
    <div class="mt-8 rounded-2xl border border-white/10 bg-white/[0.02] p-6">
        <h2 class="font-semibold text-white">Details</h2>
        <dl class="mt-5 grid sm:grid-cols-2 gap-x-8 gap-y-3 text-sm">
            @foreach ($details as $label => $value)
                <div class="flex justify-between gap-4 border-b border-white/5 pb-2.5">
                    <dt class="text-gray-500">{{ $label }}</dt>
                    <dd class="text-right text-gray-200 break-all">{{ $value ?: '—' }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    {{-- Decisions --}}
    <div class="mt-8 rounded-2xl border border-white/10 bg-white/[0.02] p-6">
        <h2 class="font-semibold text-white">Decision</h2>

        <div class="mt-5 flex flex-wrap gap-3 items-start">
            @if ($status !== 'verified')
                <form method="POST" action="{{ route('admin.verify', [$type, $owner->id]) }}">
                    @csrf
                    <button class="px-5 py-2.5 rounded-lg bg-brand-green text-black text-sm font-bold hover:bg-lime-400">
                        Verify account
                    </button>
                </form>
            @endif

            <form method="POST" action="{{ route('admin.reject', [$type, $owner->id]) }}" class="flex flex-wrap gap-2 items-start">
                @csrf
                <input type="text" name="reason" placeholder="Reason shown to the applicant" required
                       class="rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2.5 text-sm text-white w-72
                              focus:border-red-400 focus:ring-1 focus:ring-red-400 outline-none">
                <button class="px-5 py-2.5 rounded-lg border border-red-500/40 text-red-300 text-sm font-semibold hover:bg-red-500/10">
                    Reject account
                </button>
            </form>

            <form method="POST" action="{{ route('admin.status', [$type, $owner->id]) }}">
                @csrf
                @php $suspended = $owner->user?->status?->value === 'suspended'; @endphp
                <input type="hidden" name="status" value="{{ $suspended ? 'active' : 'suspended' }}">
                <button class="px-5 py-2.5 rounded-lg border border-white/20 text-gray-300 text-sm font-semibold hover:border-white/40">
                    {{ $suspended ? 'Reinstate account' : 'Suspend account' }}
                </button>
            </form>
        </div>
    </div>

</section>

@endsection
