@extends('layouts.site')

@section('title', __('site.contact.title'))
@section('description', __('site.contact.meta', ['company' => config('site.company')]))

@section('content')

<section class="max-w-5xl mx-auto px-4 sm:px-6 py-16">
    <span class="text-xs font-bold tracking-widest uppercase text-brand-green">{{ __('site.contact.label') }}</span>
    <h1 class="mt-3 text-4xl font-extrabold tracking-tight text-white">{{ __('site.contact.heading') }}</h1>
    <p class="mt-4 text-lg text-gray-400 max-w-2xl leading-relaxed">{{ __('site.contact.intro') }}</p>

    <div class="mt-12 grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach ([
            // Customer help first: it is what most people arriving here want.
            [__('site.contact.support'), config('site.email.support'), __('site.contact.support_body')],
            // No business card: that mailbox does not exist. Merchant and
            // partnership enquiries go to general, which says so.
            [__('site.contact.general'), config('site.email.info'), __('site.contact.general_body')],
            [__('site.contact.investor'), config('site.email.investors'), __('site.contact.investor_body')],
        ] as [$title, $email, $blurb])
            <div class="rounded-2xl border border-white/10 bg-white/[0.02] p-6">
                <h2 class="font-semibold text-white">{{ $title }}</h2>
                <p class="mt-1.5 text-sm text-gray-400 leading-relaxed">{{ $blurb }}</p>
                <a href="mailto:{{ $email }}" class="mt-4 inline-block text-brand-green font-semibold hover:underline break-all">
                    {{ $email }}
                </a>
            </div>
        @endforeach
    </div>

    <div class="mt-12 rounded-2xl border border-white/10 bg-white/[0.02] p-8">
        <h2 class="text-xl font-bold text-white">{{ config('site.company') }}</h2>
        <dl class="mt-5 grid sm:grid-cols-2 gap-x-8 gap-y-4 text-sm">
            <div class="flex justify-between gap-4 border-b border-white/10 pb-3">
                <dt class="text-gray-500">{{ __('site.contact.office') }}</dt>
                <dd class="text-right text-gray-200">{{ __('site.contact.office_value') }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-b border-white/10 pb-3">
                <dt class="text-gray-500">{{ __('site.contact.website') }}</dt>
                <dd class="text-right">
                    <a href="https://{{ config('site.website') }}" class="text-brand-green hover:underline">
                        {{ config('site.website') }}
                    </a>
                </dd>
            </div>
            <div class="flex justify-between gap-4 border-b border-white/10 pb-3">
                <dt class="text-gray-500">{{ __('site.contact.email') }}</dt>
                <dd class="text-right">
                    <a href="mailto:{{ config('site.email.info') }}" class="text-brand-green hover:underline break-all">
                        {{ config('site.email.info') }}
                    </a>
                </dd>
            </div>
            <div class="flex justify-between gap-4 border-b border-white/10 pb-3">
                <dt class="text-gray-500">{{ __('site.contact.founder') }}</dt>
                <dd class="text-right text-gray-200">{{ config('site.founder') }}</dd>
            </div>
        </dl>

        {{-- Leadership, for the enquiries that genuinely need a named person:
             press, partnerships, anything escalated past support. --}}
        <div class="mt-8 pt-6 border-t border-white/10">
            <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">
                {{ __('site.contact.leadership') }}
            </h3>
            <div class="mt-4 flex flex-wrap gap-x-10 gap-y-3 text-sm">
                @foreach ([
                    __('site.contact.ceo') => config('site.email.ceo'),
                    __('site.contact.cfo') => config('site.email.cfo'),
                ] as $role => $address)
                    <div>
                        <div class="text-gray-500">{{ $role }}</div>
                        <a href="mailto:{{ $address }}" class="text-brand-green hover:underline break-all">
                            {{ $address }}
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

@endsection
