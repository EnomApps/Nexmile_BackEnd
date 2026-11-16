@extends('layouts.admin')

@section('title', $rider->full_name)

@section('content')

@php
    use App\Enums\DocumentType;
    use App\Enums\RiderStatus;

    $card = 'rounded-2xl border border-white/10 bg-white/[0.02] p-6';
    $row = 'flex justify-between gap-4 border-b border-white/5 pb-2.5';
    $input = 'rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white
              focus:border-brand-orange focus:ring-1 focus:ring-brand-orange outline-none';

    $duty = $rider->duty_status->value;

    $dutyLabels = [
        RiderStatus::Available->value => 'On duty',
        RiderStatus::OnOrder->value => 'On an order',
        RiderStatus::OnBreak->value => 'On a break',
        RiderStatus::Offline->value => 'Offline',
    ];

    $dutyColours = [
        RiderStatus::Available->value => 'bg-brand-green/15 text-brand-green border-brand-green/40',
        RiderStatus::OnOrder->value => 'bg-blue-500/15 text-blue-300 border-blue-500/40',
        RiderStatus::OnBreak->value => 'bg-amber-400/15 text-amber-200 border-amber-400/40',
        RiderStatus::Offline->value => 'bg-white/5 text-gray-500 border-white/15',
    ];

    $kyc = $rider->kyc_status->value;

    $kycBadge = match ($kyc) {
        'verified' => 'bg-brand-green/15 text-brand-green border-brand-green/40',
        'submitted' => 'bg-blue-500/15 text-blue-300 border-blue-500/40',
        'rejected' => 'bg-red-500/15 text-red-300 border-red-500/40',
        default => 'bg-white/5 text-gray-400 border-white/15',
    };

    $minutes = fn (?float $value) => $value === null ? '—' : $value.' min';

    /*
     * Hoisted for the script at the foot of this page. Blade compiles a
     * directive by matching brackets, so a json directive given an array
     * subscript reads as an unclosed bracket — so the subscripts happen here,
     * and the script below is handed plain variables.
     */
    $positionUrl = route('admin.riders.position', $rider);
    $staleAfter = $presence['stale_after_seconds'];
    $liveLat = $presence['latitude'];
    $liveLng = $presence['longitude'];

    $trailJs = $trail['points']->map(fn ($point) => [
        'lat' => (float) $point->latitude,
        'lng' => (float) $point->longitude,
        'at' => $point->recorded_at->format('H:i:s'),
    ])->values();
    $money = fn (float $value) => '₹'.number_format($value, 2);
@endphp

<section class="max-w-7xl mx-auto px-4 sm:px-6 py-10">

    <a href="{{ route('admin.riders.index') }}" class="text-sm text-gray-500 hover:text-white">&larr; All riders</a>

    <div class="mt-4 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-white">{{ $rider->full_name }}</h1>
            <p class="mt-1 text-sm text-gray-500">
                Rider #{{ $rider->id }}
                · {{ $rider->user?->phone ?: 'no phone' }}
                @if ($rider->user?->email) · {{ $rider->user->email }} @endif
                @if ($rider->zone) · {{ $rider->zone->name }} @endif
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="px-3 py-1.5 rounded-full text-sm font-semibold border {{ $dutyColours[$duty] }}">
                {{ $dutyLabels[$duty] }}
            </span>
            <span class="px-3 py-1.5 rounded-full text-sm font-semibold border {{ $kycBadge }}">
                {{ ucfirst($kyc) }}
            </span>
            @if ($rider->user?->status?->value === 'suspended')
                <span class="px-3 py-1.5 rounded-full text-sm font-semibold border bg-red-500/15 text-red-300 border-red-500/40">
                    Suspended
                </span>
            @endif
        </div>
    </div>

    @if ($standing['flagged'])
        <p class="mt-4 rounded-lg bg-amber-400/10 border border-amber-400/30 text-amber-200 px-4 py-3 text-sm">
            Flagged for review — {{ implode(' · ', $standing['reasons']) }}.
            <a href="{{ route('admin.conduct.rider', $rider) }}" class="underline hover:text-white">Read the conduct file</a>.
        </p>
    @endif

    @if (session('status'))
        <p class="mt-4 rounded-lg bg-brand-green/10 border border-brand-green/30 text-brand-green px-4 py-3 text-sm">
            {{ session('status') }}
        </p>
    @endif

    {{-- ──────────────────────────────── Live ──────────────────────────────── --}}
    <div class="mt-8 {{ $card }}">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="font-semibold text-white">Where they are now</h2>
                <p class="mt-1 text-sm text-gray-500 max-w-2xl leading-relaxed">
                    Positions live in Redis and are overwritten, so this is the
                    latest fix rather than a record. The trail further down is
                    the record.
                </p>
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-400 shrink-0">
                <input type="checkbox" id="follow" class="rounded border-white/20 bg-white/5">
                Keep refreshing
            </label>
        </div>

        <div class="mt-5 grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded-xl border border-white/10 bg-black/30 p-4">
                <div class="text-xs text-gray-500">Reporting</div>
                <div class="mt-1 font-semibold" id="presence-state">
                    @if ($presence['present'])
                        <span class="text-brand-green">Yes</span>
                    @elseif ($duty !== RiderStatus::Offline->value)
                        <span class="text-amber-300">No — but on duty</span>
                    @else
                        <span class="text-gray-500">No</span>
                    @endif
                </div>
                <div class="mt-1 text-xs text-gray-600">
                    Presence lapses after {{ config('dispatch.presence_ttl_seconds') }}s
                </div>
            </div>

            <div class="rounded-xl border border-white/10 bg-black/30 p-4">
                <div class="text-xs text-gray-500">Last fix</div>
                <div class="mt-1 font-semibold {{ $presence['stale'] ? 'text-amber-300' : 'text-gray-200' }}"
                     id="presence-age">
                    {{ $presence['recorded_at']?->diffForHumans() ?? 'never' }}
                </div>
                <div class="mt-1 text-xs text-gray-600" id="presence-source">{{ $presence['source'] }}</div>
            </div>

            <div class="rounded-xl border border-white/10 bg-black/30 p-4">
                <div class="text-xs text-gray-500">Position</div>
                <div class="mt-1 font-mono text-sm text-gray-200" id="presence-coords">
                    @if ($presence['latitude'] !== null)
                        {{ number_format($presence['latitude'], 6) }}, {{ number_format($presence['longitude'], 6) }}
                    @else
                        —
                    @endif
                </div>
                @if ($presence['latitude'] !== null)
                    <a id="presence-link" target="_blank" rel="noopener"
                       href="https://www.google.com/maps?q={{ $presence['latitude'] }},{{ $presence['longitude'] }}"
                       class="mt-1 inline-block text-xs text-brand-orange hover:underline">
                        Open in Google Maps
                    </a>
                @endif
            </div>

            <div class="rounded-xl border border-white/10 bg-black/30 p-4">
                <div class="text-xs text-gray-500">Speed</div>
                <div class="mt-1 font-semibold text-gray-200" id="presence-speed">
                    {{ $presence['speed'] === null ? '—' : round($presence['speed'] * 3.6).' km/h' }}
                </div>
                <div class="mt-1 text-xs text-gray-600">
                    {{ $presence['heading'] === null ? 'no heading' : 'heading '.round($presence['heading']).'°' }}
                </div>
            </div>
        </div>

        @if ($mapKey)
            <div id="map" class="mt-5 h-80 rounded-xl border border-white/10 bg-black/40"></div>
        @else
            {{-- No browser key issued yet. Saying so beats an empty grey box
                 that looks like a broken map. --}}
            <p class="mt-5 rounded-lg bg-white/[0.03] border border-white/10 text-gray-500 px-4 py-3 text-xs leading-relaxed">
                No map drawn: <code class="text-gray-400">GOOGLE_MAPS_BROWSER_KEY</code> is not set.
                The server key cannot be used here — it is restricted to this
                machine's IP address, and a key in a page is public. Issue a
                Maps JavaScript API key restricted by HTTP referrer and the map
                and the trail below both draw themselves.
            </p>
        @endif
    </div>

    {{-- ─────────────────────────── Performance ──────────────────────────── --}}
    <div class="mt-8 grid lg:grid-cols-3 gap-6">
        <div class="{{ $card }} lg:col-span-2">
            <h2 class="font-semibold text-white">Performance</h2>
            <p class="mt-1 text-sm text-gray-500">
                Timings are the mean over the last {{ $performance['sample'] }}
                {{ \Illuminate\Support\Str::plural('delivery', $performance['sample']) }}, not since they joined —
                a lifetime average hides a rider who has got slower.
            </p>

            <div class="mt-5 grid sm:grid-cols-3 gap-4">
                <div class="rounded-xl border border-white/10 bg-black/30 p-4">
                    <div class="text-xs text-gray-500">Delivered</div>
                    <div class="mt-1 text-2xl font-bold text-white">{{ $performance['completed_deliveries'] }}</div>
                </div>
                <div class="rounded-xl border border-white/10 bg-black/30 p-4">
                    <div class="text-xs text-gray-500">Rating</div>
                    <div class="mt-1 text-2xl font-bold text-white">
                        {{ $performance['rating'] === null ? '—' : number_format($performance['rating'], 1) }}
                    </div>
                    <div class="text-xs text-gray-600">{{ $performance['rating_count'] }} ratings</div>
                </div>
                <div class="rounded-xl border border-white/10 bg-black/30 p-4">
                    <div class="text-xs text-gray-500">Accepts offers</div>
                    <div class="mt-1 text-2xl font-bold text-white">
                        {{ $performance['acceptance_rate'] === null ? '—' : $performance['acceptance_rate'].'%' }}
                    </div>
                    <div class="text-xs text-gray-600">
                        {{ $performance['offers_accepted'] }} of {{ $performance['offers_received'] }}
                    </div>
                </div>
            </div>

            <dl class="mt-5 grid sm:grid-cols-2 gap-x-8 gap-y-3 text-sm">
                <div class="{{ $row }}">
                    <dt class="text-gray-500">Kitchen to door</dt>
                    <dd class="text-gray-200">{{ $minutes($performance['minutes_riding']) }}</dd>
                </div>
                <div class="{{ $row }}">
                    <dt class="text-gray-500">Assigned to delivered</dt>
                    <dd class="text-gray-200">{{ $minutes($performance['minutes_total']) }}</dd>
                </div>
                <div class="{{ $row }}">
                    <dt class="text-gray-500">Waiting at the counter</dt>
                    <dd class="text-gray-200">{{ $minutes($performance['minutes_waiting']) }}</dd>
                </div>
                <div class="{{ $row }}">
                    <dt class="text-gray-500">Offers missed in a row</dt>
                    <dd class="{{ $performance['consecutive_misses'] > 0 ? 'text-amber-300' : 'text-gray-200' }}">
                        {{ $performance['consecutive_misses'] }}
                        @if ($performance['auto_offline_after'] > 0)
                            <span class="text-xs text-gray-600">
                                / {{ $performance['auto_offline_after'] }} before auto-offline
                            </span>
                        @endif
                    </dd>
                </div>
            </dl>
        </div>

        {{-- ───────────────────────── Earnings ────────────────────────── --}}
        <div class="{{ $card }}">
            <h2 class="font-semibold text-white">Earnings</h2>
            <p class="mt-1 text-sm text-gray-500">
                Earned comes from the orders; paid comes from the weekly
                statements. The gap is work no statement covers yet.
            </p>

            <dl class="mt-5 space-y-3 text-sm">
                <div class="{{ $row }}">
                    <dt class="text-gray-500">Earned, all deliveries</dt>
                    <dd class="text-gray-200">{{ $money($earnings['delivered_total']) }}</dd>
                </div>
                <div class="{{ $row }}">
                    <dt class="text-gray-500">Paid out</dt>
                    <dd class="text-brand-green">{{ $money($earnings['paid']) }}</dd>
                </div>
                <div class="{{ $row }}">
                    <dt class="text-gray-500">Owed, not yet sent</dt>
                    <dd class="{{ $earnings['awaiting'] > 0 ? 'text-amber-300' : 'text-gray-200' }}">
                        {{ $money($earnings['awaiting']) }}
                    </dd>
                </div>
                <div class="{{ $row }}">
                    <dt class="text-gray-500">Carried to next week</dt>
                    <dd class="text-gray-200">{{ $money($earnings['carried']) }}</dd>
                </div>
                <div class="{{ $row }}">
                    <dt class="text-gray-500">Since last statement</dt>
                    <dd class="text-gray-200">
                        {{ $money($earnings['since_statement']) }}
                        @if ($earnings['last_period_end'])
                            <span class="block text-xs text-gray-600">
                                after {{ $earnings['last_period_end']->format('d M Y') }}
                            </span>
                        @endif
                    </dd>
                </div>
            </dl>

            @unless ($earnings['bank_on_file'])
                <p class="mt-4 rounded-lg bg-brand-orange/10 border border-brand-orange/30 text-brand-orange px-3 py-2.5 text-xs">
                    No bank details on file, so a payout run will skip this rider.
                </p>
            @endunless

            @if ($earnings['payouts']->isNotEmpty())
                <div class="mt-5 pt-4 border-t border-white/5">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Statements</div>
                    <div class="mt-3 space-y-2">
                        @foreach ($earnings['payouts'] as $payout)
                            <div class="flex items-center justify-between gap-3 text-xs">
                                <span class="text-gray-400">
                                    {{ $payout->period_start->format('d M') }}–{{ $payout->period_end->format('d M Y') }}
                                </span>
                                <span class="text-gray-200">{{ $money((float) $payout->net) }}</span>
                                <span class="{{ $payout->status === 'paid' ? 'text-brand-green' : 'text-gray-500' }}">
                                    {{ ucfirst($payout->status) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
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
                {{ $counts['cancelled'] }} cancelled ·
                {{ $counts['rejected'] }} rejected
            </div>
        </div>

        <div class="mt-5 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                        <th class="pb-3 pr-4">Order</th>
                        <th class="pb-3 pr-4">Restaurant</th>
                        <th class="pb-3 pr-4">Customer</th>
                        <th class="pb-3 pr-4">Dropoff</th>
                        <th class="pb-3 pr-4">Status</th>
                        <th class="pb-3">Assigned → delivered</th>
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
                                <div class="text-xs text-gray-600">#{{ $order->id }}</div>
                            </td>
                            <td class="py-3 pr-4 text-gray-400">{{ $order->merchant?->business_name ?? '—' }}</td>
                            <td class="py-3 pr-4 text-gray-400">{{ $order->customer?->name ?? '—' }}</td>
                            <td class="py-3 pr-4 text-gray-400">{{ $order->delivery_city ?? '—' }}</td>
                            <td class="py-3 pr-4">
                                <span class="text-xs text-gray-300">{{ $order->status->value }}</span>
                            </td>
                            <td class="py-3 text-xs text-gray-500">
                                {{ $order->assigned_at?->format('d M H:i') ?? '—' }}
                                →
                                {{ $order->delivered_at?->format('H:i') ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-sm text-gray-600">
                                This rider has never been assigned an order.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-5">{{ $orders->links() }}</div>
    </div>

    {{-- ──────────────────────── Location history ────────────────────────── --}}
    <div class="mt-8 {{ $card }}">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="font-semibold text-white">Where they went</h2>
                <p class="mt-1 text-sm text-gray-500 max-w-2xl leading-relaxed">
                    Kept for {{ $retentionDays }} days, then deleted — this is
                    personal data about somebody's working day, so the period is
                    a commitment rather than however long the table grew.
                    Only fixes that moved the rider more than
                    {{ config('tracking.history.min_metres') }} m are stored, so a
                    rider standing still is one point, not hundreds.
                </p>
            </div>

            @if ($trailDays->isNotEmpty())
                <form method="GET" class="flex items-end gap-2 shrink-0">
                    <div>
                        <label for="date" class="block text-xs text-gray-500 mb-1.5">Day</label>
                        <select id="date" name="date" class="{{ $input }}" onchange="this.form.submit()">
                            @foreach ($trailDays as $day)
                                <option value="{{ $day }}" @selected($day === $trailDate->toDateString())>
                                    {{ \Illuminate\Support\Carbon::parse($day)->format('D d M Y') }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </form>
            @endif
        </div>

        @unless ($historyEnabled)
            <p class="mt-4 rounded-lg bg-amber-400/10 border border-amber-400/30 text-amber-200 px-4 py-3 text-sm">
                Trail recording is switched off — <code>TRACKING_HISTORY_ENABLED</code> is false.
                Nothing new is being stored.
            </p>
        @endunless

        @if ($trail['points']->isEmpty())
            <p class="mt-5 text-sm text-gray-600">
                Nothing recorded on {{ $trailDate->format('D d M Y') }}.
            </p>
        @else
            @if ($trail['truncated'])
                <p class="mt-4 rounded-lg bg-white/[0.03] border border-white/10 text-gray-400 px-4 py-2.5 text-xs">
                    Showing the first {{ $trail['limit'] }} points of this day. There are more.
                </p>
            @endif

            @if ($mapKey)
                <div id="trail-map" class="mt-5 h-96 rounded-xl border border-white/10 bg-black/40"></div>
            @endif

            <div class="mt-5 max-h-96 overflow-y-auto rounded-xl border border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-white/[0.03] sticky top-0">
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-2.5">Time</th>
                            <th class="px-4 py-2.5">Position</th>
                            <th class="px-4 py-2.5">Speed</th>
                            <th class="px-4 py-2.5">Accuracy</th>
                            <th class="px-4 py-2.5">Order</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @foreach ($trail['points'] as $point)
                            <tr class="hover:bg-white/[0.02]">
                                <td class="px-4 py-2 text-gray-300 whitespace-nowrap">
                                    {{ $point->recorded_at->format('H:i:s') }}
                                </td>
                                <td class="px-4 py-2">
                                    <a href="https://www.google.com/maps?q={{ $point->latitude }},{{ $point->longitude }}"
                                       target="_blank" rel="noopener"
                                       class="font-mono text-xs text-gray-400 hover:text-brand-orange">
                                        {{ number_format((float) $point->latitude, 5) }},
                                        {{ number_format((float) $point->longitude, 5) }}
                                    </a>
                                </td>
                                <td class="px-4 py-2 text-xs text-gray-500">
                                    {{ $point->speed === null ? '—' : round((float) $point->speed * 3.6).' km/h' }}
                                </td>
                                <td class="px-4 py-2 text-xs {{ $point->accuracy_metres !== null && $point->accuracy_metres > 50 ? 'text-amber-300' : 'text-gray-500' }}">
                                    {{ $point->accuracy_metres === null ? '—' : $point->accuracy_metres.' m' }}
                                </td>
                                <td class="px-4 py-2 text-xs">
                                    @if ($point->order_id)
                                        <a href="{{ route('admin.orders.show', $point->order_id) }}"
                                           class="text-gray-400 hover:text-brand-orange">#{{ $point->order_id }}</a>
                                    @else
                                        <span class="text-gray-700">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ─────────────────────────── Activity ─────────────────────────────── --}}
    <div class="mt-8 {{ $card }}">
        <h2 class="font-semibold text-white">Sign-ins and activity</h2>
        <p class="mt-1 text-sm text-gray-500 max-w-2xl leading-relaxed">
            Read from the app's sessions. A sign-in is a new session; the app
            renewing one it already had is marked as a renewal, so a rider who
            left the app open for a week does not read as signing in nightly.
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
                'Joined' => $rider->created_at?->format('d M Y'),
                'Account created' => $rider->user?->created_at?->format('d M Y'),
                'Date of birth' => $rider->date_of_birth?->format('d M Y'),
                'Vehicle' => trim(($rider->vehicle_type ?? '').' '.($rider->vehicle_number ?? '')),
                'Licence' => $rider->driving_licence_no,
                'Licence expiry' => $rider->driving_licence_expiry?->format('d M Y'),
                'RC number' => $rider->rc_number,
                'Insurance' => $rider->insurance_number,
                'Insurance expiry' => $rider->insurance_expiry?->format('d M Y'),
                'PAN' => $rider->pan,
                'Bank account name' => $rider->bank_account_name,
                'Bank IFSC' => $rider->bank_ifsc,
                'App language' => $rider->user?->preferred_locale,
                'Verified on' => $rider->kyc_verified_at?->format('d M Y'),
            ] as $label => $value)
                <div class="{{ $row }}">
                    <dt class="text-gray-500">{{ $label }}</dt>
                    <dd class="text-right text-gray-200 break-all">{{ $value ?: '—' }}</dd>
                </div>
            @endforeach
        </dl>

        {{-- Aadhaar and the bank account number are deliberately absent. They
             are on the model's $hidden list, they are not needed to answer any
             question this page exists for, and a screen an admin leaves open
             on a desk should not be showing them. --}}
        <p class="mt-4 text-xs text-gray-600">
            Aadhaar and the full bank account number are not shown here. Read
            them off the documents below when they are actually needed.
        </p>

        @if ($rider->kyc_rejection_reason)
            <p class="mt-4 rounded-lg bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 text-sm">
                {{ $rider->kyc_rejection_reason }}
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
                                <form method="POST" action="{{ route('admin.documents.review', ['riders', $rider->id, $doc->id]) }}">
                                    @csrf
                                    <input type="hidden" name="status" value="approved">
                                    <button class="px-3 py-1.5 rounded-lg bg-brand-green/20 text-brand-green text-xs font-semibold hover:bg-brand-green/30">
                                        Approve
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('admin.documents.review', ['riders', $rider->id, $doc->id]) }}"
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

    {{-- ──────────────────────────── Decision ───────────────────────────── --}}
    <div class="mt-8 {{ $card }}">
        <h2 class="font-semibold text-white">Decision</h2>

        <div class="mt-5 flex flex-wrap gap-3 items-start">
            @if ($kyc !== 'verified')
                <form method="POST" action="{{ route('admin.verify', ['riders', $rider->id]) }}">
                    @csrf
                    <button class="px-5 py-2.5 rounded-lg bg-brand-green text-black text-sm font-bold hover:bg-lime-400">
                        Verify rider
                    </button>
                </form>
            @endif

            <form method="POST" action="{{ route('admin.reject', ['riders', $rider->id]) }}" class="flex flex-wrap gap-2 items-start">
                @csrf
                <input type="text" name="reason" placeholder="Reason shown to the rider" required
                       class="rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2.5 text-sm text-white w-72
                              focus:border-red-400 focus:ring-1 focus:ring-red-400 outline-none">
                <button class="px-5 py-2.5 rounded-lg border border-red-500/40 text-red-300 text-sm font-semibold hover:bg-red-500/10">
                    Reject
                </button>
            </form>

            <form method="POST" action="{{ route('admin.status', ['riders', $rider->id]) }}">
                @csrf
                @php $suspended = $rider->user?->status?->value === 'suspended'; @endphp
                <input type="hidden" name="status" value="{{ $suspended ? 'active' : 'suspended' }}">
                <button class="px-5 py-2.5 rounded-lg border border-white/20 text-gray-300 text-sm font-semibold hover:border-white/40">
                    {{ $suspended ? 'Reinstate account' : 'Suspend account' }}
                </button>
            </form>

            <a href="{{ route('admin.conduct.rider', $rider) }}"
               class="px-5 py-2.5 rounded-lg border border-white/20 text-gray-300 text-sm font-semibold hover:border-white/40">
                Conduct file
            </a>
        </div>
    </div>

</section>

@push('scripts')
<script>
    (function () {
        'use strict';

        const endpoint = @json($positionUrl);
        const staleAfter = @json($staleAfter);
        const trailPoints = @json($trailJs);

        const el = (id) => document.getElementById(id);
        const follow = el('follow');

        /*
         * Polling, not a socket. One admin watching one rider is a request
         * every ten seconds; a websocket for that is infrastructure to
         * operate, monitor and secure for no benefit anybody would notice.
         */
        let timer = null;
        let marker = null;

        function paint(data) {
            const coords = el('presence-coords');
            const age = el('presence-age');
            const source = el('presence-source');
            const speed = el('presence-speed');
            const link = el('presence-link');

            if (data.latitude !== null && data.longitude !== null) {
                if (coords) {
                    coords.textContent = data.latitude.toFixed(6) + ', ' + data.longitude.toFixed(6);
                }
                if (link) {
                    link.href = 'https://www.google.com/maps?q=' + data.latitude + ',' + data.longitude;
                }
                if (marker && window.google) {
                    const position = { lat: data.latitude, lng: data.longitude };
                    marker.setPosition(position);
                }
            }

            if (age) {
                age.textContent = data.seconds_ago === null
                    ? 'never'
                    : data.seconds_ago + 's ago';
                age.classList.toggle('text-amber-300', data.stale);
                age.classList.toggle('text-gray-200', !data.stale);
            }

            if (source) { source.textContent = data.source; }

            if (speed) {
                speed.textContent = data.speed === null
                    ? '—'
                    : Math.round(data.speed * 3.6) + ' km/h';
            }

            const state = el('presence-state');
            if (state) {
                state.innerHTML = data.present
                    ? '<span class="text-brand-green">Yes</span>'
                    : (data.duty_status !== 'offline'
                        ? '<span class="text-amber-300">No — but on duty</span>'
                        : '<span class="text-gray-500">No</span>');
            }
        }

        async function refresh() {
            try {
                const response = await fetch(endpoint, { headers: { 'Accept': 'application/json' } });
                if (!response.ok) { return; }
                const body = await response.json();
                paint(body.data);
            } catch (error) {
                // A failed poll is not worth telling the admin about; the
                // age on screen going stale says it better than an alert.
            }
        }

        if (follow) {
            follow.addEventListener('change', function () {
                if (follow.checked) {
                    refresh();
                    // Half the staleness window, so a position cannot be
                    // shown as fresh when it has already expired.
                    timer = setInterval(refresh, Math.max(5, Math.floor(staleAfter / 2)) * 1000);
                } else if (timer) {
                    clearInterval(timer);
                    timer = null;
                }
            });
        }

        // Only defined when a browser key was configured; the maps script
        // calls it once it has loaded.
        window.nexmileMaps = function () {
            const live = el('map');
            const lat = @json($liveLat);
            const lng = @json($liveLng);

            if (live && lat !== null && lng !== null) {
                const map = new google.maps.Map(live, {
                    center: { lat: lat, lng: lng },
                    zoom: 15,
                    mapId: 'nexmile-rider',
                });
                marker = new google.maps.Marker({ position: { lat: lat, lng: lng }, map: map });
            }

            const trail = el('trail-map');

            if (trail && trailPoints.length > 0) {
                const path = trailPoints.map((p) => ({ lat: p.lat, lng: p.lng }));
                const map = new google.maps.Map(trail, { center: path[0], zoom: 14 });

                new google.maps.Polyline({
                    path: path,
                    map: map,
                    geodesic: true,
                    strokeOpacity: 0.9,
                    strokeWeight: 3,
                });

                // Where the day started and where it ended, because a line
                // with no direction is two different stories.
                new google.maps.Marker({ position: path[0], map: map, label: 'A' });
                new google.maps.Marker({ position: path[path.length - 1], map: map, label: 'B' });

                const bounds = new google.maps.LatLngBounds();
                path.forEach((point) => bounds.extend(point));
                map.fitBounds(bounds);
            }
        };
    })();
</script>

@if ($mapKey)
    <script async defer
            src="https://maps.googleapis.com/maps/api/js?key={{ $mapKey }}&callback=nexmileMaps"></script>
@endif
@endpush

@endsection
