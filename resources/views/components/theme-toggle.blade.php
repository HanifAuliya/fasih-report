@props(['compact' => false])

@php
    $modes = ['light' => ['sun', 'Terang'], 'dark' => ['moon', 'Gelap'], 'system' => ['monitor', 'Sistem']];
@endphp

<div {{ $attributes->merge(['class' => 'inline-flex items-center gap-0.5 rounded-lg border border-slate-200 bg-slate-50 p-0.5']) }} x-data role="radiogroup" aria-label="Tema">
    @foreach ($modes as $mode => [$icon, $label])
        <button type="button" role="radio" title="Tema {{ $label }}"
            x-on:click="$store.theme.set('{{ $mode }}')"
            :aria-checked="$store.theme.mode === '{{ $mode }}'"
            :class="$store.theme.mode === '{{ $mode }}' ? 'bg-(--surface) text-slate-900 shadow-(--shadow-xs) ring-1 ring-slate-200' : 'text-slate-400 hover:text-slate-700'"
            class="inline-flex h-7 items-center justify-center gap-1.5 rounded-md px-1.5 text-xs font-medium transition">
            <x-icon :name="$icon" class="size-3.5" />
            @unless ($compact)
                <span>{{ $label }}</span>
            @endunless
        </button>
    @endforeach
</div>
