@extends('layouts.site')

@section('title', $doc['title'])
@section('description', $doc['meta'])

@section('content')

{{-- One template for all three policies. They share a shape — a heading, a
     last-updated date, numbered sections of prose — and keeping them in one
     layout means a change to that shape happens once.

     The copy lives in config/legal.php rather than in a translation file: it
     is deliberately English-only, and machine-translated legal terms would be
     worse than none. --}}
<section class="max-w-3xl mx-auto px-4 sm:px-6 py-16">

    <span class="text-xs font-bold tracking-widest uppercase text-brand-green">{{ __('site.legal.label') }}</span>
    <h1 class="mt-3 text-4xl font-extrabold tracking-tight text-white">{{ $doc['title'] }}</h1>

    <p class="mt-4 text-sm text-gray-500">
        {{ __('site.legal.updated') }} {{ $doc['updated'] }}
        <span class="mx-2 text-gray-700">·</span>
        {{ config('legal.entity') }}
    </p>

    @if (! empty($doc['intro']))
        <p class="mt-8 text-lg text-gray-300 leading-relaxed">{{ $doc['intro'] }}</p>
    @endif

    <div class="mt-10 space-y-10">
        @foreach ($doc['sections'] as $i => $section)
            <div>
                <h2 class="text-xl font-bold text-white">
                    <span class="text-gray-600 mr-2">{{ $i + 1 }}.</span>{{ $section['heading'] }}
                </h2>

                <div class="mt-3 space-y-3 text-gray-400 leading-relaxed">
                    @foreach ($section['body'] as $block)
                        @if (is_array($block))
                            <ul class="space-y-2 pl-5 list-disc marker:text-gray-600">
                                @foreach ($block as $point)
                                    <li>{{ $point }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p>{{ $block }}</p>
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    {{-- Required by the Information Technology (Intermediary Guidelines and
         Digital Media Ethics Code) Rules, 2021: a named officer, contactable,
         published on the platform. --}}
    <div class="mt-14 rounded-2xl border border-white/10 bg-white/[0.02] p-6">
        <h2 class="font-bold text-white">{{ __('site.legal.grievance') }}</h2>
        <p class="mt-2 text-sm text-gray-400 leading-relaxed">{{ __('site.legal.grievance_body') }}</p>

        {{-- Blank entries are skipped, not rendered empty. The officer's name
             and the registered address are still to be supplied, and a row
             reading "Officer:" followed by nothing looks like a fault. The
             contact email is published either way, so there is always a
             working route to a complaint. --}}
        <dl class="mt-4 space-y-2 text-sm">
            @foreach (array_filter([
                __('site.legal.officer') => config('legal.grievance.name'),
                __('site.contact.email') => config('legal.grievance.email'),
                __('site.legal.address') => config('legal.address'),
            ], fn ($value) => filled($value)) as $label => $value)
                <div class="flex flex-wrap justify-between gap-4 border-b border-white/5 pb-2">
                    <dt class="text-gray-500">{{ $label }}</dt>
                    <dd class="text-right text-gray-200">
                        @if (str_contains((string) $value, '@'))
                            <a href="mailto:{{ $value }}" class="text-brand-green hover:underline">{{ $value }}</a>
                        @else
                            {{ $value }}
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>

        <p class="mt-4 text-xs text-gray-600">{{ __('site.legal.response_time') }}</p>
    </div>

    <nav class="mt-10 flex flex-wrap gap-x-6 gap-y-2 text-sm">
        @foreach (['terms', 'privacy', 'refunds'] as $slug)
            @if ($slug !== $current)
                <a href="{{ route($slug) }}" class="text-brand-green hover:underline">
                    {{ config("legal.documents.{$slug}.title") }}
                </a>
            @endif
        @endforeach
    </nav>

</section>

@endsection
