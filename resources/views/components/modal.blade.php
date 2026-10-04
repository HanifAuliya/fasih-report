@props(['title' => null, 'maxWidth' => 'lg'])

@php
    $width = [
        'sm' => 'max-w-sm',
        'md' => 'max-w-md',
        'lg' => 'max-w-lg',
        'xl' => 'max-w-xl',
        '2xl' => 'max-w-2xl',
        '4xl' => 'max-w-4xl',
        '6xl' => 'max-w-6xl',
    ][$maxWidth];
@endphp

<div x-data="{ show: @entangle($attributes->wire('model')) }" x-show="show" x-cloak
    @keydown.escape.window="show = false" class="fixed inset-0 z-50 overflow-y-auto">
    <div x-show="show" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="show = false"></div>

    <div class="relative flex min-h-full items-start justify-center p-4 sm:p-10">
        <div x-show="show" x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2 scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            class="relative w-full {{ $width }} rounded-xl bg-white shadow-2xl ring-1 ring-slate-900/5">
            @if ($title)
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <h3 class="text-base font-semibold text-slate-900">{{ $title }}</h3>
                    <button type="button" class="btn-icon" @click="show = false">
                        <x-icon name="x" class="size-5" />
                    </button>
                </div>
            @endif

            {{ $slot }}
        </div>
    </div>
</div>
