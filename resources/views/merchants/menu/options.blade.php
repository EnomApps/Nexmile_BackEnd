@extends('layouts.site')

@section('title', __('portal.options.title'))

@section('content')

@php
    $card = 'rounded-2xl border border-white/10 bg-white/[0.02] p-6';
    $input = 'w-full rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2 text-sm text-white
              focus:border-brand-green focus:ring-1 focus:ring-brand-green outline-none';
    $label = 'block text-sm font-medium mb-1.5 text-gray-300';
    // Enough blank rows to add a typical group in one go; empties are dropped.
    $blankRows = 5;
@endphp

<section class="max-w-3xl mx-auto px-4 sm:px-6 py-12">

    <a href="{{ route('merchants.menu.index') }}" class="text-sm text-gray-500 hover:text-brand-green">
        &larr; {{ __('portal.menu.title') }}
    </a>

    <h1 class="mt-4 text-3xl font-extrabold tracking-tight text-white">{{ $item->name }}</h1>
    <p class="mt-1 text-sm text-gray-500">{{ __('portal.options.intro') }}</p>

    @if (session('status'))
        <div class="mt-6 rounded-lg bg-brand-green/10 border border-brand-green/30 text-brand-green px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mt-6 rounded-lg bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 text-sm space-y-1">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    {{-- Existing groups --}}
    @forelse ($groups as $group)
        <div class="mt-6 {{ $card }}">
            <form method="POST" action="{{ route('merchants.menu.options.update', $group->id) }}">
                @csrf @method('PATCH')

                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex-1 min-w-[14rem]">
                        <label class="{{ $label }}" for="name-{{ $group->id }}">{{ __('portal.options.group_name') }}</label>
                        <input id="name-{{ $group->id }}" name="name" value="{{ $group->name }}" required class="{{ $input }}">
                    </div>

                    <div class="w-40">
                        <label class="{{ $label }}" for="selection-{{ $group->id }}">{{ __('portal.options.selection') }}</label>
                        <select id="selection-{{ $group->id }}" name="selection" class="{{ $input }}">
                            <option value="single" @selected($group->selection === 'single')>{{ __('portal.options.single') }}</option>
                            <option value="multiple" @selected($group->selection === 'multiple')>{{ __('portal.options.multiple') }}</option>
                        </select>
                    </div>
                </div>

                <label class="mt-3 flex items-center gap-2 text-sm text-gray-300">
                    <input type="hidden" name="is_required" value="0">
                    <input type="checkbox" name="is_required" value="1" @checked($group->is_required)
                           class="rounded border-white/20 bg-transparent text-brand-orange focus:ring-brand-orange">
                    {{ __('portal.options.required') }}
                </label>

                <div class="mt-5 space-y-2">
                    @foreach ($group->options as $i => $option)
                        <div class="flex flex-wrap items-center gap-2">
                            <input type="hidden" name="options[{{ $i }}][id]" value="{{ $option->id }}">
                            <input name="options[{{ $i }}][name]" value="{{ $option->name }}"
                                   class="{{ $input }} flex-1 min-w-[10rem]">
                            <div class="flex items-center gap-1.5">
                                <span class="text-sm text-gray-600">₹</span>
                                <input name="options[{{ $i }}][price_delta]" type="number" step="0.01"
                                       value="{{ (float) $option->price_delta }}" class="{{ $input }} w-28">
                            </div>
                            <label class="flex items-center gap-1.5 text-xs text-gray-400">
                                <input type="hidden" name="options[{{ $i }}][is_available]" value="0">
                                <input type="checkbox" name="options[{{ $i }}][is_available]" value="1"
                                       @checked($option->is_available)
                                       class="rounded border-white/20 bg-transparent text-brand-green focus:ring-brand-green">
                                {{ __('portal.menu.available') }}
                            </label>
                        </div>
                    @endforeach

                    {{-- Blank rows to append to this group. --}}
                    @for ($i = $group->options->count(); $i < $group->options->count() + 2; $i++)
                        <div class="flex flex-wrap items-center gap-2">
                            <input name="options[{{ $i }}][name]" placeholder="{{ __('portal.options.add_option') }}"
                                   class="{{ $input }} flex-1 min-w-[10rem]">
                            <div class="flex items-center gap-1.5">
                                <span class="text-sm text-gray-600">₹</span>
                                <input name="options[{{ $i }}][price_delta]" type="number" step="0.01" placeholder="0"
                                       class="{{ $input }} w-28">
                            </div>
                        </div>
                    @endfor
                </div>

                <p class="mt-3 text-xs text-gray-600">{{ __('portal.options.remove_hint') }}</p>

                <div class="mt-4 flex items-center gap-3">
                    <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-green text-black text-sm font-bold hover:bg-lime-400 transition">
                        {{ __('portal.options.save_group') }}
                    </button>
                </div>
            </form>

            <form method="POST" action="{{ route('merchants.menu.options.destroy', $group->id) }}" class="mt-3"
                  onsubmit="return confirm('{{ __('portal.options.delete_confirm') }}')">
                @csrf @method('DELETE')
                <button type="submit" class="text-xs text-gray-500 hover:text-red-400">
                    {{ __('portal.options.delete_group') }}
                </button>
            </form>
        </div>
    @empty
        <p class="mt-6 {{ $card }} text-center text-sm text-gray-500">{{ __('portal.options.none') }}</p>
    @endforelse

    {{-- New group --}}
    <div class="mt-8 {{ $card }}">
        <h2 class="font-bold text-white">{{ __('portal.options.new_group') }}</h2>

        <form method="POST" action="{{ route('merchants.menu.items.options.store', $item->id) }}" class="mt-4">
            @csrf

            <div class="flex flex-wrap items-start gap-3">
                <div class="flex-1 min-w-[14rem]">
                    <label class="{{ $label }}" for="new-name">{{ __('portal.options.group_name') }}</label>
                    <input id="new-name" name="name" required placeholder="{{ __('portal.options.name_example') }}"
                           value="{{ old('name') }}" class="{{ $input }}">
                </div>

                <div class="w-40">
                    <label class="{{ $label }}" for="new-selection">{{ __('portal.options.selection') }}</label>
                    <select id="new-selection" name="selection" class="{{ $input }}">
                        <option value="single">{{ __('portal.options.single') }}</option>
                        <option value="multiple">{{ __('portal.options.multiple') }}</option>
                    </select>
                </div>
            </div>

            <label class="mt-3 flex items-center gap-2 text-sm text-gray-300">
                <input type="hidden" name="is_required" value="0">
                <input type="checkbox" name="is_required" value="1" @checked(old('is_required'))
                       class="rounded border-white/20 bg-transparent text-brand-orange focus:ring-brand-orange">
                {{ __('portal.options.required') }}
            </label>

            <div class="mt-5 space-y-2">
                @for ($i = 0; $i < $blankRows; $i++)
                    <div class="flex flex-wrap items-center gap-2">
                        <input name="options[{{ $i }}][name]" value="{{ old("options.{$i}.name") }}"
                               placeholder="{{ $i === 0 ? __('portal.options.option_example') : __('portal.options.add_option') }}"
                               class="{{ $input }} flex-1 min-w-[10rem]">
                        <div class="flex items-center gap-1.5">
                            <span class="text-sm text-gray-600">₹</span>
                            <input name="options[{{ $i }}][price_delta]" type="number" step="0.01" placeholder="0"
                                   value="{{ old("options.{$i}.price_delta") }}" class="{{ $input }} w-28">
                        </div>
                    </div>
                @endfor
            </div>

            <button type="submit" class="mt-5 px-6 py-3 rounded-lg bg-brand-orange text-black font-bold hover:bg-orange-400 transition">
                {{ __('portal.options.add_group') }}
            </button>
        </form>
    </div>

</section>

@endsection
