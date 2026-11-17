@extends('layouts.admin')

@section('title', $merchant->business_name)

@section('content')

@php
    use App\Enums\DocumentType;

    $card = 'rounded-2xl border border-white/10 bg-white/[0.02] p-6';
    $row = 'flex justify-between gap-4 border-b border-white/5 pb-2.5';
    $input = 'rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white
              focus:border-brand-orange focus:ring-1 focus:ring-brand-orange outline-none';

    $kyc = $merchant->kyc_status->value;

    $kycBadge = match ($kyc) {
        'verified' => 'bg-brand-green/15 text-brand-green border-brand-green/40',
        'submitted' => 'bg-blue-500/15 text-blue-300 border-blue-500/40',
        'rejected' => 'bg-red-500/15 text-red-300 border-red-500/40',
        default => 'bg-white/5 text-gray-400 border-white/15',
    };

    $minutes = fn (?float $value) => $value === null ? '—' : $value.' min';
    $money = fn ($value) => '₹'.number_format((float) $value, 2);
@endphp

<section class="max-w-7xl mx-auto px-4 sm:px-6 py-10">

    <a href="{{ route('admin.merchants.index') }}" class="text-sm text-gray-500 hover:text-white">&larr; All restaurants</a>

    <div class="mt-4 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-white">{{ $merchant->business_name }}</h1>
            <p class="mt-1 text-sm text-gray-500">
                #{{ $merchant->id }} · {{ $merchant->owner_name }}
                · {{ $merchant->business_phone ?: $merchant->user?->phone ?: 'no phone' }}
                · {{ $merchant->city }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($state['can_receive'])
                <span class="px-3 py-1.5 rounded-full text-sm font-semibold border bg-brand-green/15 text-brand-green border-brand-green/40">
                    Taking orders
                </span>
            @else
                <span class="px-3 py-1.5 rounded-full text-sm font-semibold border bg-white/5 text-gray-400 border-white/15">
                    Not taking orders
                </span>
            @endif

            <span class="px-3 py-1.5 rounded-full text-sm font-semibold border {{ $kycBadge }}">
                {{ ucfirst($kyc) }}
            </span>

            @if ($merchant->user?->status?->value === 'suspended')
                <span class="px-3 py-1.5 rounded-full text-sm font-semibold border bg-red-500/15 text-red-300 border-red-500/40">
                    Suspended
                </span>
            @endif
        </div>
    </div>

    @if (session('status'))
        <p class="mt-4 rounded-lg bg-brand-green/10 border border-brand-green/30 text-brand-green px-4 py-3 text-sm">
            {{ session('status') }}
        </p>
    @endif

    {{-- ─────────────────────── Why it is or isn't open ──────────────────── --}}
    <div class="mt-8 {{ $card }}">
        <h2 class="font-semibold text-white">Can it take an order right now?</h2>
        <p class="mt-1 text-sm text-gray-500 max-w-2xl leading-relaxed">
            Four separate conditions, all of which have to hold. They look the
            same from a customer's phone — the restaurant simply isn't there —
            so this is the only place the difference is visible.
        </p>

        <div class="mt-5 grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach ([
                ['Owner has it switched on', $state['accepting'], $state['accepting'] ? 'accepting' : 'paused by the restaurant'],
                ['Inside opening hours', $state['open_now'], $state['open_now'] ? 'open now' : 'closed by the clock'],
                ['Verified', $state['verified'], $state['verified'] ? 'KYC approved' : 'not verified yet'],
                ['Food licence valid', $state['fssai_blocker'] === null, $state['fssai_blocker'] ?? 'FSSAI in date'],
            ] as [$label, $ok, $detail])
                <div class="rounded-xl border {{ $ok ? 'border-white/10' : 'border-amber-400/40' }} bg-black/30 p-4">
                    <div class="text-xs text-gray-500">{{ $label }}</div>
                    <div class="mt-1 font-semibold {{ $ok ? 'text-brand-green' : 'text-amber-300' }}">
                        {{ $ok ? 'Yes' : 'No' }}
                    </div>
                    <div class="mt-1 text-xs text-gray-600">{{ $detail }}</div>
                </div>
            @endforeach
        </div>

        @if ($menu['empty'])
            <p class="mt-4 rounded-lg bg-amber-400/10 border border-amber-400/30 text-amber-200 px-4 py-3 text-sm">
                No menu items at all. Everything above can be green and there is still nothing to order.
            </p>
        @elseif ($menu['nothing_orderable'])
            <p class="mt-4 rounded-lg bg-amber-400/10 border border-amber-400/30 text-amber-200 px-4 py-3 text-sm">
                All {{ $menu['total'] }} menu items are marked out of stock, so nothing here can be ordered.
            </p>
        @endif
    </div>

    {{-- ─────────────────────── Performance and money ────────────────────── --}}
    <div class="mt-8 grid lg:grid-cols-3 gap-6">
        <div class="{{ $card }} lg:col-span-2">
            <h2 class="font-semibold text-white">Performance</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-2xl leading-relaxed">
                Timings are the mean over the last {{ $performance['sample'] }}
                delivered orders. The rejection rate is over everything the
                kitchen was actually asked to make — orders abandoned at
                payment never reached them and would only flatter it.
            </p>

            <div class="mt-5 grid sm:grid-cols-3 gap-4">
                <div class="rounded-xl border border-white/10 bg-black/30 p-4">
                    <div class="text-xs text-gray-500">Rejection rate</div>
                    <div class="mt-1 text-2xl font-bold {{ ($performance['rejection_rate'] ?? 0) >= 10 ? 'text-amber-300' : 'text-white' }}">
                        {{ $performance['rejection_rate'] === null ? '—' : $performance['rejection_rate'].'%' }}
                    </div>
                    <div class="text-xs text-gray-600">
                        {{ $performance['rejected'] }} of {{ $performance['orders_asked'] }} asked
                    </div>
                </div>

                <div class="rounded-xl border border-white/10 bg-black/30 p-4">
                    <div class="text-xs text-gray-500">Rating</div>
                    <div class="mt-1 text-2xl font-bold text-white">
                        {{ $performance['rating'] === null ? '—' : number_format($performance['rating'], 1) }}
                    </div>
                    <div class="text-xs text-gray-600">{{ $performance['rating_count'] }} ratings</div>
                </div>

                <div class="rounded-xl border border-white/10 bg-black/30 p-4">
                    <div class="text-xs text-gray-500">Time to accept</div>
                    <div class="mt-1 text-2xl font-bold text-white">
                        {{ $performance['minutes_to_accept'] === null ? '—' : $performance['minutes_to_accept'] }}
                    </div>
                    <div class="text-xs text-gray-600">minutes after the order is placed</div>
                </div>
            </div>

            {{-- The number every customer ETA is built on. When it is wrong,
                 it is wrong in the same direction all day, for every order
                 this restaurant takes. --}}
            <div class="mt-5 rounded-xl border {{ abs($performance['prep_gap'] ?? 0) >= 5 ? 'border-amber-400/40' : 'border-white/10' }} bg-black/30 p-4">
                <div class="font-medium text-white">Prep time — promised against actual</div>

                <dl class="mt-3 grid sm:grid-cols-3 gap-x-8 gap-y-3 text-sm">
                    <div class="{{ $row }}">
                        <dt class="text-gray-500">Promised</dt>
                        <dd class="text-gray-200">{{ $minutes($performance['minutes_prep_promised']) }}</dd>
                    </div>
                    <div class="{{ $row }}">
                        <dt class="text-gray-500">Actual</dt>
                        <dd class="text-gray-200">{{ $minutes($performance['minutes_prep_actual']) }}</dd>
                    </div>
                    <div class="{{ $row }}">
                        <dt class="text-gray-500">Difference</dt>
                        <dd class="{{ abs($performance['prep_gap'] ?? 0) >= 5 ? 'text-amber-300' : 'text-gray-200' }}">
                            @if ($performance['prep_gap'] === null)
                                —
                            @else
                                {{ $performance['prep_gap'] > 0 ? '+' : '' }}{{ $performance['prep_gap'] }} min
                            @endif
                        </dd>
                    </div>
                </dl>

                @if ($performance['prep_gap'] !== null && abs($performance['prep_gap']) >= 5)
                    <p class="mt-3 text-xs text-amber-200/80">
                        Every arrival time quoted for this restaurant is out by about this much,
                        in the same direction, on every order. Their profile setting is
                        {{ $performance['prep_setting'] ?? 'unset' }} minutes.
                    </p>
                @endif
            </div>
        </div>

        <div class="{{ $card }}">
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="font-semibold text-white">Earnings</h2>
                <span class="text-xs text-gray-600">{{ $from->format('d M') }}–{{ $to->format('d M') }}</span>
            </div>
            <p class="mt-1 text-sm text-gray-500">
                The same figures the restaurant sees on their own earnings
                page, from the same service — so the two cannot disagree.
            </p>

            <dl class="mt-5 space-y-3 text-sm">
                <div class="{{ $row }}">
                    <dt class="text-gray-500">Delivered orders</dt>
                    <dd class="text-gray-200">{{ $earnings['orders'] }}</dd>
                </div>
                <div class="{{ $row }}">
                    <dt class="text-gray-500">Gross sales</dt>
                    <dd class="text-gray-200">{{ $money($earnings['gross']) }}</dd>
                </div>
                <div class="{{ $row }}">
                    <dt class="text-gray-500">Commission</dt>
                    <dd class="text-gray-200">
                        {{ $money($earnings['commission']) }}
                        <span class="block text-xs text-gray-600">at {{ $earnings['commission_rate'] }}%</span>
                    </dd>
                </div>
                <div class="{{ $row }}">
                    <dt class="text-gray-500">Owed to them</dt>
                    <dd class="text-brand-green">{{ $money($earnings['payout']) }}</dd>
                </div>
                <div class="{{ $row }}">
                    <dt class="text-gray-500">Average order</dt>
                    <dd class="text-gray-200">{{ $money($earnings['average_order']) }}</dd>
                </div>
            </dl>

            @if ($state['upcoming_change'])
                <p class="mt-4 rounded-lg bg-white/[0.03] border border-white/10 text-gray-400 px-3 py-2.5 text-xs">
                    Commission changes to {{ $merchant->scheduled_commission_rate }}% on
                    {{ $merchant->commission_changes_on?->format('d M Y') }}. They have been shown
                    this on their earnings page since it was set.
                </p>
            @endif

            <form method="GET" class="mt-5 pt-4 border-t border-white/5 flex flex-wrap items-end gap-2">
                <div>
                    <label for="from" class="block text-xs text-gray-500 mb-1.5">From</label>
                    <input id="from" name="from" type="date" value="{{ $from->toDateString() }}" class="{{ $input }}">
                </div>
                <div>
                    <label for="to" class="block text-xs text-gray-500 mb-1.5">To</label>
                    <input id="to" name="to" type="date" value="{{ $to->toDateString() }}" class="{{ $input }}">
                </div>
                <button class="px-4 py-2 rounded-lg border border-white/20 text-gray-300 text-sm font-semibold hover:border-white/40">
                    Show
                </button>
            </form>
        </div>
    </div>

    {{-- ──────────────────────────── Orders ──────────────────────────────── --}}
    <div class="mt-8 {{ $card }}">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 class="font-semibold text-white">Orders</h2>
            <div class="text-xs text-gray-500">
                {{ $counts['total'] }} total ·
                <span class="text-brand-green">{{ $counts['delivered'] }} delivered</span> ·
                <span class="text-blue-300">{{ $counts['ongoing'] }} ongoing</span> ·
                <span class="{{ $counts['rejected'] > 0 ? 'text-amber-300' : '' }}">{{ $counts['rejected'] }} rejected</span> ·
                {{ $counts['cancelled'] }} cancelled
            </div>
        </div>

        <div class="mt-5 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                        <th class="pb-3 pr-4">Order</th>
                        <th class="pb-3 pr-4">Customer</th>
                        <th class="pb-3 pr-4">Rider</th>
                        <th class="pb-3 pr-4">Status</th>
                        <th class="pb-3 pr-4">Total</th>
                        <th class="pb-3">Placed → ready</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    @forelse ($orders as $order)
                        <tr class="hover:bg-white/[0.02]">
                            <td class="py-3 pr-4">
                                <a href="{{ route('admin.orders.show', $order) }}"
                                   class="text-gray-200 hover:text-white font-medium">
                                    {{ $order->order_number }}
                                </a>
                            </td>
                            <td class="py-3 pr-4 text-gray-400">{{ $order->customer?->name ?? '—' }}</td>
                            <td class="py-3 pr-4 text-gray-400">
                                @if ($order->rider)
                                    <a href="{{ route('admin.riders.show', $order->rider_id) }}"
                                       class="hover:text-brand-orange">{{ $order->rider->full_name }}</a>
                                @else
                                    <span class="text-gray-700">—</span>
                                @endif
                            </td>
                            <td class="py-3 pr-4 text-xs text-gray-300">{{ $order->status->value }}</td>
                            <td class="py-3 pr-4 text-gray-400">{{ $money($order->grand_total) }}</td>
                            <td class="py-3 text-xs text-gray-500">
                                {{ $order->placed_at?->format('d M H:i') ?? '—' }}
                                →
                                {{ $order->ready_at?->format('H:i') ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-sm text-gray-600">
                                This restaurant has never taken an order.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-5">{{ $orders->links() }}</div>
    </div>

    {{-- ─────────────────────────── Activity ─────────────────────────────── --}}
    <div class="mt-8 {{ $card }}">
        <h2 class="font-semibold text-white">Sign-ins and activity</h2>
        <p class="mt-1 text-sm text-gray-500 max-w-2xl leading-relaxed">
            Whether anybody is actually logged into the merchant portal, or
            the tablet in the kitchen has been signed out since Tuesday. A
            renewal is marked apart from a sign-in — the app renews quietly,
            and counting those as sign-ins makes the column meaningless.
        </p>

        @if ($sessions->isEmpty())
            <p class="mt-5 text-sm text-gray-600">This account has never signed in.</p>
        @else
            <div class="mt-5 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="pb-3 pr-4">Started</th>
                            <th class="pb-3 pr-4">Device</th>
                            <th class="pb-3 pr-4">IP</th>
                            <th class="pb-3 pr-4">Last active</th>
                            <th class="pb-3">Ended</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @foreach ($sessions as $session)
                            <tr class="hover:bg-white/[0.02]">
                                <td class="py-3 pr-4 text-gray-300 whitespace-nowrap">
                                    {{ $session['signed_in_at']?->format('d M Y H:i') ?? '—' }}
                                    @unless ($session['is_sign_in'])
                                        <span class="block text-xs text-gray-600">renewal</span>
                                    @endunless
                                </td>
                                <td class="py-3 pr-4 text-gray-400">{{ $session['device_name'] ?: '—' }}</td>
                                <td class="py-3 pr-4 font-mono text-xs text-gray-500">{{ $session['ip_address'] ?: '—' }}</td>
                                <td class="py-3 pr-4 text-gray-400">
                                    {{ $session['last_used_at']?->diffForHumans() ?? 'never used' }}
                                </td>
                                <td class="py-3">
                                    @if ($session['signed_out_at'])
                                        <span class="text-xs text-gray-500">
                                            signed out {{ $session['signed_out_at']->format('d M H:i') }}
                                        </span>
                                    @elseif ($session['active'])
                                        <span class="text-xs text-brand-green">active</span>
                                    @else
                                        <span class="text-xs text-gray-600">expired</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ──────────────────── Registration and documents ──────────────────── --}}
    <div class="mt-8 {{ $card }}">
        <h2 class="font-semibold text-white">Registration</h2>

        <dl class="mt-5 grid sm:grid-cols-2 gap-x-8 gap-y-3 text-sm">
            @foreach ([
                'Owner' => $merchant->owner_name,
                'Business phone' => $merchant->business_phone,
                'Business email' => $merchant->business_email,
                'Address' => trim(($merchant->address_line1 ?? '').', '.($merchant->city ?? '').' '.($merchant->pincode ?? ''), ', '),
                'Joined' => $merchant->created_at?->format('d M Y'),
                'FSSAI' => $merchant->fssai_license_no,
                'FSSAI expiry' => $merchant->fssai_expiry_date?->format('d M Y'),
                'GSTIN' => $merchant->gstin,
                'PAN' => $merchant->pan,
                'Bank account name' => $merchant->bank_account_name,
                'Bank IFSC' => $merchant->bank_ifsc,
                'Packaging fee' => $merchant->packaging_fee,
                'Minimum order' => $merchant->min_order_value,
                'Self pickup' => $merchant->supports_pickup ? 'Yes' : 'No',
                'Verified on' => $merchant->kyc_verified_at?->format('d M Y'),
            ] as $label => $value)
                <div class="{{ $row }}">
                    <dt class="text-gray-500">{{ $label }}</dt>
                    <dd class="text-right text-gray-200 break-all">{{ $value !== null && $value !== '' ? $value : '—' }}</dd>
                </div>
            @endforeach
        </dl>

        {{-- The full bank account number is on the model's $hidden list and
             stays off this screen for the same reason it does on the rider
             page: nothing here needs it. --}}
        <p class="mt-4 text-xs text-gray-600">
            The full bank account number is not shown here. Read it off the bank proof below when it is needed.
        </p>

        @if ($merchant->kyc_rejection_reason)
            <p class="mt-4 rounded-lg bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 text-sm">
                {{ $merchant->kyc_rejection_reason }}
            </p>
        @endif

        <div class="mt-6 pt-6 border-t border-white/5">
            <h3 class="font-semibold text-white">Documents</h3>

            @if ($documents->isEmpty())
                <p class="mt-3 text-sm text-gray-600">Nothing uploaded yet.</p>
            @else
                <div class="mt-4 space-y-3">
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
                                        {{-- Link expires in minutes; reload for a fresh one. --}}
                                        <a href="{{ $url }}" target="_blank" rel="noopener"
                                           class="px-3 py-1.5 rounded-lg bg-white/10 text-white text-xs font-semibold hover:bg-white/20">
                                            View
                                        </a>
                                    @endif
                                </div>
                            </div>

                            <div class="mt-3 pt-3 border-t border-white/5 flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('admin.documents.review', ['merchants', $merchant->id, $doc->id]) }}">
                                    @csrf
                                    <input type="hidden" name="status" value="approved">
                                    <button class="px-3 py-1.5 rounded-lg bg-brand-green/20 text-brand-green text-xs font-semibold hover:bg-brand-green/30">
                                        Approve
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('admin.documents.review', ['merchants', $merchant->id, $doc->id]) }}"
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
    </div>

    {{-- ───────────────────────── Food licence ───────────────────────────── --}}
    @php $hasLicence = $merchant->fssai_license_no && $merchant->fssai_expiry_date?->isFuture(); @endphp

    <div class="mt-8 rounded-2xl border {{ $hasLicence ? 'border-white/10' : 'border-brand-orange/40' }} bg-white/[0.02] p-6">
        <h2 class="font-semibold text-white">Food licence</h2>
        <p class="mt-1 text-sm text-gray-500">
            Copy these from the FSSAI certificate uploaded above. Admin only —
            a merchant who could type their own number would make verification
            pointless.
        </p>

        @unless ($hasLicence)
            <p class="mt-4 rounded-lg bg-brand-orange/10 border border-brand-orange/30 text-brand-orange px-4 py-3 text-sm">
                {{ $merchant->fssai_license_no
                    ? 'This licence has expired, so the restaurant cannot open.'
                    : 'No licence recorded, so this restaurant cannot open — even once verified.' }}
            </p>
        @endunless

        <form method="POST" action="{{ route('admin.merchants.compliance', $merchant->id) }}"
              class="mt-5 flex flex-wrap items-end gap-3">
            @csrf

            <div>
                <label for="fssai_license_no" class="block text-xs text-gray-500 mb-1.5">FSSAI licence number</label>
                <input id="fssai_license_no" name="fssai_license_no" required inputmode="numeric"
                       placeholder="14 digits"
                       value="{{ old('fssai_license_no', $merchant->fssai_license_no) }}"
                       class="{{ $input }} w-48">
            </div>

            <div>
                <label for="fssai_expiry_date" class="block text-xs text-gray-500 mb-1.5">Valid until</label>
                <input id="fssai_expiry_date" name="fssai_expiry_date" type="date" required
                       value="{{ old('fssai_expiry_date', $merchant->fssai_expiry_date?->toDateString()) }}"
                       class="{{ $input }}">
            </div>

            <div>
                <label for="gstin" class="block text-xs text-gray-500 mb-1.5">
                    GSTIN <span class="text-gray-700">(optional)</span>
                </label>
                <input id="gstin" name="gstin" maxlength="15" placeholder="15 characters"
                       value="{{ old('gstin', $merchant->gstin) }}"
                       class="{{ $input }} w-52">
            </div>

            <button class="px-5 py-2.5 rounded-lg bg-brand-orange text-black text-sm font-bold hover:bg-orange-400">
                Save licence
            </button>

            <p class="w-full text-xs text-gray-600">
                An expired licence blocks the restaurant from opening whatever their KYC status —
                a lapsed food licence is a legal problem, not a warning.
            </p>
        </form>
    </div>

    {{-- ──────────────────────── Commercial terms ────────────────────────── --}}
    <div class="mt-8 {{ $card }}">
        <h2 class="font-semibold text-white">Commercial terms</h2>
        <p class="mt-1 text-sm text-gray-500">
            Taken from food and packaging on each delivered order. The delivery
            fee is not commissionable — it pays the rider.
        </p>

        @if ((float) $merchant->commission_rate === 0.0)
            <p class="mt-4 rounded-lg bg-brand-orange/10 border border-brand-orange/30 text-brand-orange px-4 py-3 text-sm">
                This restaurant is on 0% — every order it takes earns Nexmile nothing.
            </p>
        @endif

        <form method="POST" action="{{ route('admin.merchants.terms', $merchant->id) }}"
              class="mt-5 flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label for="commission_rate" class="block text-xs text-gray-500 mb-1.5">Commission rate</label>
                <div class="flex items-center gap-2">
                    <input id="commission_rate" name="commission_rate" type="number" step="0.01"
                           min="0" max="{{ config('checkout.max_commission_rate') }}" required
                           value="{{ old('commission_rate', (float) $merchant->commission_rate) }}"
                           class="{{ $input }} w-32">
                    <span class="text-sm text-gray-500">%</span>
                </div>
            </div>

            <div>
                <label for="scheduled_commission_rate" class="block text-xs text-gray-500 mb-1.5">Then changes to</label>
                <div class="flex items-center gap-2">
                    <input id="scheduled_commission_rate" name="scheduled_commission_rate" type="number" step="0.01"
                           min="0" max="{{ config('checkout.max_commission_rate') }}"
                           value="{{ old('scheduled_commission_rate', $merchant->scheduled_commission_rate === null ? '' : (float) $merchant->scheduled_commission_rate) }}"
                           class="{{ $input }} w-32">
                    <span class="text-sm text-gray-500">%</span>
                </div>
            </div>

            <div>
                <label for="commission_changes_on" class="block text-xs text-gray-500 mb-1.5">On</label>
                <input id="commission_changes_on" name="commission_changes_on" type="date"
                       value="{{ old('commission_changes_on', $merchant->commission_changes_on?->toDateString()) }}"
                       class="{{ $input }}">
            </div>

            <button class="px-5 py-2.5 rounded-lg bg-brand-orange text-black text-sm font-bold hover:bg-orange-400">
                Save rate
            </button>

            <p class="w-full text-xs text-gray-600">
                Applies to new orders only — orders already placed keep the commission they were priced
                with, because those figures are on invoices and payout statements.
            </p>
        </form>
    </div>

    {{-- ──────────────────────────── Decision ───────────────────────────── --}}
    <div class="mt-8 {{ $card }}">
        <h2 class="font-semibold text-white">Decision</h2>

        <div class="mt-5 flex flex-wrap gap-3 items-start">
            @if ($kyc !== 'verified')
                <form method="POST" action="{{ route('admin.verify', ['merchants', $merchant->id]) }}">
                    @csrf
                    <button class="px-5 py-2.5 rounded-lg bg-brand-green text-black text-sm font-bold hover:bg-lime-400">
                        Verify restaurant
                    </button>
                </form>
            @endif

            <form method="POST" action="{{ route('admin.reject', ['merchants', $merchant->id]) }}" class="flex flex-wrap gap-2 items-start">
                @csrf
                <input type="text" name="reason" placeholder="Reason shown to the applicant" required
                       class="{{ $input }} w-72">
                <button class="px-5 py-2.5 rounded-lg border border-red-500/40 text-red-300 text-sm font-semibold hover:bg-red-500/10">
                    Reject
                </button>
            </form>

            <form method="POST" action="{{ route('admin.status', ['merchants', $merchant->id]) }}">
                @csrf
                @php $suspended = $merchant->user?->status?->value === 'suspended'; @endphp
                <input type="hidden" name="status" value="{{ $suspended ? 'active' : 'suspended' }}">
                <button class="px-5 py-2.5 rounded-lg border border-white/20 text-gray-300 text-sm font-semibold hover:border-white/40">
                    {{ $suspended ? 'Reinstate account' : 'Suspend account' }}
                </button>
            </form>
        </div>
    </div>

</section>

@endsection
