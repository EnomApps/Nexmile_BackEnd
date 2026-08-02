@extends('layouts.admin')

@section('title', 'Review queue')

@section('content')

@php
    $tabs = [
        'submitted' => 'Awaiting review',
        'pending' => 'Not submitted',
        'verified' => 'Verified',
        'rejected' => 'Rejected',
    ];
@endphp

<section class="max-w-6xl mx-auto px-4 sm:px-6 py-10">

    <h1 class="text-2xl font-bold text-white">Review queue</h1>

    <div class="mt-6 flex flex-wrap gap-2">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('admin.index', ['status' => $key]) }}"
               class="px-4 py-2 rounded-lg text-sm font-medium border transition
                      {{ $status === $key
                         ? 'bg-brand-orange text-black border-brand-orange'
                         : 'border-white/15 text-gray-400 hover:text-white hover:border-white/30' }}">
                {{ $label }}
                <span class="ml-1.5 opacity-70">{{ $counts[$key] ?? 0 }}</span>
            </a>
        @endforeach
    </div>

    @foreach ([['Merchants', $merchants, 'merchants'], ['Riders', $riders, 'riders']] as [$heading, $rows, $type])
        <div class="mt-10">
            <h2 class="font-semibold text-white">{{ $heading }}</h2>

            @if ($rows->isEmpty())
                <p class="mt-3 text-sm text-gray-600">Nothing here.</p>
            @else
                <div class="mt-4 overflow-x-auto rounded-2xl border border-white/10">
                    <table class="w-full text-sm">
                        <thead class="bg-white/[0.03] text-gray-400">
                            <tr>
                                <th class="text-left font-medium px-4 py-3">Name</th>
                                <th class="text-left font-medium px-4 py-3">Contact</th>
                                <th class="text-left font-medium px-4 py-3">Location</th>
                                <th class="text-left font-medium px-4 py-3">Docs</th>
                                <th class="text-left font-medium px-4 py-3">Submitted</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            @foreach ($rows as $row)
                                <tr class="hover:bg-white/[0.02]">
                                    <td class="px-4 py-3 text-white font-medium">
                                        {{ $type === 'merchants' ? $row->business_name : $row->full_name }}
                                    </td>
                                    <td class="px-4 py-3 text-gray-400">
                                        {{ $row->user?->phone ?? '—' }}<br>
                                        <span class="text-xs text-gray-600 break-all">{{ $row->user?->email }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-400">
                                        {{ $type === 'merchants' ? ($row->city ?? '—') : ($row->zone_id ? "Zone {$row->zone_id}" : '—') }}
                                    </td>
                                    <td class="px-4 py-3 text-gray-400">{{ $row->kyc_documents_count }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $row->updated_at?->diffForHumans() }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('admin.show', [$type, $row->id]) }}"
                                           class="px-3 py-1.5 rounded-lg bg-white/10 text-white text-xs font-semibold hover:bg-white/20">
                                            Review
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">{{ $rows->appends(['status' => $status])->links() }}</div>
            @endif
        </div>
    @endforeach

</section>

@endsection
