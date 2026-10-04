@props(['label', 'value', 'hint' => null, 'icon' => null, 'progress' => null])

<div {{ $attributes->merge(['class' => 'card flex flex-col p-5']) }}>
    <div class="flex items-center justify-between gap-3">
        <p class="text-[13px] font-medium text-slate-500">{{ $label }}</p>
        @if ($icon)
            <span class="flex size-8 items-center justify-center rounded-lg bg-slate-100 text-slate-500">
                <x-icon :name="$icon" class="size-4" />
            </span>
        @endif
    </div>
    <p class="mt-3 text-[26px] leading-none font-semibold tracking-tight text-slate-900 tabular-nums">{{ $value }}</p>
    @if ($progress !== null)
        <x-progress :value="$progress" size="sm" class="mt-3" />
    @endif
    @if ($hint)
        <p class="mt-2 truncate text-xs text-slate-500">{{ $hint }}</p>
    @endif
</div>
