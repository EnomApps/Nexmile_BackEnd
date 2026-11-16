@extends('layouts.admin')

@section('title', 'Riders')

@section('content')

@php
    use App\Enums\KycStatus;
    use App\Enums\RiderStatus;

    $card = 'rounded-2xl border border-white/10 bg-white/[0.02] p-5';
    $input = 'rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white
              focus:border-brand-orange focus:ring-1 focus:ring-brand-orange outline-none';

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

    // Flipping a column that is already sorted reverses it; a new column
    // starts at the direction that answers the obvious question — worst
    // acceptance first, most recent sighting first, names from A.
    $flip = function (string $column) use ($filters) {
        $natural = in_array($column, ['name', 'acceptance'], true) ? 'asc' : 'desc';

        return [
            'sort' => $column,
            'dir' => $filters['sort'] === $column
                ? ($filters['dir'] === 'asc' ? 'desc' : 'asc')
                : $natural,
        ] + array_filter([
            'q' => $filters['q'],
            'duty' => $filters['duty'],
            'kyc' => $filters['kyc'],
            'zone' => $filters['zone'],
        ], fn ($value) => $value !== null && $value !== '');
    };

    $heading = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500';
@endphp

<section class="max-w-7xl mx-auto px-4 sm:px-6 py-10">

    <h1 class="text-2xl font-bold text-white">Riders</h1>
    <p class="mt-1 text-sm text-gray-500 max-w-3xl leading-relaxed">
        Everyone on the roster, and whether their phone is actually reporting.
        <span class="text-gray-400">On duty</span> is what the rider set;
        <span class="text-gray-400">reporting</span> is whether a position has
        arrived in the last {{ config('dispatch.presence_ttl_seconds') }} seconds.
        A rider who is on duty and not reporting is invisible to dispatch and
        usually does not know it — that pairing is the one worth acting on.
    </p>

    {{-- Duty status, as counts rather than a dropdown: the first question an
         admin has at six o'clock is how many riders are actually on. --}}
    <nav class="mt-6 flex flex-wrap gap-2">
        @foreach (['all' => 'Everyone'] + $dutyLabels as $key => $label)
            @php $active = $key === 'all' ? $filters['duty'] === null : $filters['duty'] === $key; @endphp
            <a href="{{ route('admin.riders.index', array_filter([
                    'duty' => $key === 'all' ? null : $key,
                    'q' => $filters['q'] ?: null,
                    'kyc' => $filters['kyc'],
                    'zone' => $filters['zone'],
               ])) }}"
               class="px-3.5 py-1.5 rounded-lg border text-sm font-medium transition
                      {{ $active
                            ? 'border-brand-orange text-brand-orange bg-brand-orange/10'
                            : 'border-white/15 text-gray-400 hover:text-white' }}">
                {{ $label }}
                <span class="ml-1 text-xs opacity-70">{{ $counts[$key] ?? 0 }}</span>
            </a>
        @endforeach
    </nav>

    <form method="GET" action="{{ route('admin.riders.index') }}" class="mt-5 flex flex-wrap items-end gap-3">
        {{-- Carried so that searching does not silently drop the duty filter
             the admin clicked a moment ago. --}}
        <input type="hidden" name="duty" value="{{ $filters['duty'] }}">
        <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
        <input type="hidden" name="dir" value="{{ $filters['dir'] }}">

        <div class="flex-1 min-w-[16rem]">
            <label for="q" class="block text-xs text-gray-500 mb-1.5">Search</label>
            <input id="q" name="q" value="{{ $filters['q'] }}"
                   placeholder="Name, phone, email, number plate or rider id"
                   class="w-full {{ $input }}">
        </div>

        <div>
            <label for="kyc" class="block text-xs text-gray-500 mb-1.5">Verification</label>
            <select id="kyc" name="kyc" class="{{ $input }}">
                <option value="">Any</option>
                @foreach (KycStatus::cases() as $case)
                    <option value="{{ $case->value }}" @selected($filters['kyc'] === $case->value)>
                        {{ ucfirst($case->value) }}
                    </option>
                @endforeach
            </select>
        </div>

        @if ($zones->isNotEmpty())
            <div>
                <label for="zone" class="block text-xs text-gray-500 mb-1.5">Zone</label>
                <select id="zone" name="zone" class="{{ $input }}">
                    <option value="">Any</option>
                    @foreach ($zones as $zone)
                        <option value="{{ $zone->id }}" @selected((int) $filters['zone'] === $zone->id)>
                            {{ $zone->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        <button class="px-5 py-2 rounded-lg bg-brand-orange text-black text-sm font-bold hover:bg-orange-400">
            Search
        </button>

        @if ($filters['q'] !== '' || $filters['kyc'] || $filters['zone'])
            <a href="{{ route('admin.riders.index') }}" class="px-3 py-2 text-sm text-gray-500 hover:text-white">
                Clear
            </a>
        @endif
    </form>

    <div class="mt-6 rounded-2xl border border-white/10 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-white/[0.03]">
                    <tr>
                        <th class="{{ $heading }}">
                            <a href="{{ route('admin.riders.index', $flip('name')) }}" class="hover:text-white">Rider</a>
                        </th>
                        <th class="{{ $heading }}">Duty</th>
                        <th class="{{ $heading }}">
                            <a href="{{ route('admin.riders.index', $flip('seen')) }}" class="hover:text-white">Reporting</a>
                        </th>
                        <th class="{{ $heading }}">Verification</th>
                        <th class="{{ $heading }}">
                            <a href="{{ route('admin.riders.index', $flip('deliveries')) }}" class="hover:text-white">Delivered</a>
                        </th>
                        <th class="{{ $heading }}">
                            <a href="{{ route('admin.riders.index', $flip('acceptance')) }}" class="hover:text-white">Accepts</a>
                        </th>
                        <th class="{{ $heading }}">
                            <a href="{{ route('admin.riders.index', $flip('rating')) }}" class="hover:text-white">Rating</a>
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-white/5">
                    @forelse ($riders as $rider)
                        @php
                            $reporting = $presence[$rider->id] ?? false;
                            $duty = $rider->duty_status->value;
                            $onDuty = $duty !== RiderStatus::Offline->value;
                            $standing = $standings[$rider->id] ?? null;
                        @endphp

                        <tr class="hover:bg-white/[0.02]">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.riders.show', $rider) }}" class="group">
                                    <div class="font-medium text-gray-200 group-hover:text-white">
                                        {{ $rider->full_name }}
                                    </div>
                                    <div class="mt-0.5 text-xs text-gray-600">
                                        #{{ $rider->id }} · {{ $rider->user?->phone ?: 'no phone' }}
                                        @if ($rider->zone) · {{ $rider->zone->name }} @endif
                                    </div>
                                </a>

                                @if ($standing && $standing['flagged'])
                                    <div class="mt-1 text-xs text-amber-200/80">
                                        {{ implode(' · ', $standing['reasons']) }}
                                    </div>
                                @endif

                                @if ($rider->user?->status?->value === 'suspended')
                                    <div class="mt-1 text-xs text-red-300">Account suspended</div>
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                <span class="px-2.5 py-1 rounded-lg border text-xs font-semibold {{ $dutyColours[$duty] }}">
                                    {{ $dutyLabels[$duty] }}
                                </span>
                            </td>

                            <td class="px-4 py-3">
                                @if ($reporting)
                                    <span class="text-brand-green text-xs font-semibold">Yes</span>
                                @elseif ($onDuty)
                                    {{-- The pairing worth acting on: on duty, and dispatch
                                         cannot see them. --}}
                                    <span class="text-amber-300 text-xs font-semibold">No — on duty</span>
                                @else
                                    <span class="text-gray-600 text-xs">No</span>
                                @endif

                                <div class="mt-0.5 text-xs text-gray-600">
                                    {{ $rider->last_location_at?->diffForHumans() ?? 'never' }}
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                <span class="text-xs {{ $rider->kyc_status === \App\Enums\KycStatus::Verified ? 'text-brand-green' : 'text-gray-400' }}">
                                    {{ ucfirst($rider->kyc_status->value) }}
                                </span>
                            </td>

                            <td class="px-4 py-3 text-gray-300">{{ $rider->completed_deliveries }}</td>

                            <td class="px-4 py-3 text-gray-300">
                                @if ($rider->acceptanceRate() === null)
                                    <span class="text-gray-600">never offered</span>
                                @else
                                    {{ $rider->acceptanceRate() }}%
                                    <span class="text-xs text-gray-600">
                                        ({{ $rider->offers_accepted }}/{{ $rider->offers_received }})
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-gray-300">
                                @if ($rider->rating === null)
                                    <span class="text-gray-600">—</span>
                                @else
                                    {{ number_format((float) $rider->rating, 1) }}
                                    <span class="text-xs text-gray-600">({{ $rider->rating_count }})</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-sm text-gray-600">
                                No riders match that.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">{{ $riders->links() }}</div>

</section>

@endsection
