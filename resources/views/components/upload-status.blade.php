{{--
    Status upload: bar persen saat file dikirim ke server (event upload Livewire dari input wire:model="$upload"),
    lalu animasi "sedang diproses" selama aksi $process berjalan.
--}}
@props(['upload', 'process' => null, 'processLabel' => 'Memproses data…'])

@php
    $isThisInput = "\$event.target.getAttribute?.('wire:model') === ".\Illuminate\Support\Js::from($upload);
@endphp

<div x-data="{ uploading: false, progress: 0 }"
    x-on:livewire-upload-start.window="if ({{ $isThisInput }}) { uploading = true; progress = 0 }"
    x-on:livewire-upload-progress.window="if ({{ $isThisInput }}) progress = $event.detail.progress"
    x-on:livewire-upload-finish.window="if ({{ $isThisInput }}) uploading = false"
    x-on:livewire-upload-error.window="if ({{ $isThisInput }}) uploading = false"
    x-on:livewire-upload-cancel.window="if ({{ $isThisInput }}) uploading = false"
    {{ $attributes }}>
    <div x-show="uploading" x-cloak class="rounded-xl border border-brand-100 bg-brand-50 px-3 py-2.5 dark:border-brand-900 dark:bg-brand-950/40">
        <div class="flex items-center justify-between gap-3 text-xs font-medium text-brand-700 dark:text-brand-300">
            <span class="flex items-center gap-2">
                <svg class="size-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3" /><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                Mengunggah file…
            </span>
            <span class="tabular-nums" x-text="progress + '%'"></span>
        </div>
        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-brand-100 dark:bg-brand-900">
            <div class="h-full rounded-full bg-brand-500 transition-[width] duration-200" :style="`width: ${progress}%`"></div>
        </div>
    </div>

    @if ($process)
        <div wire:loading.block wire:target="{{ $process }}" class="rounded-xl border border-brand-100 bg-brand-50 px-3 py-2.5 dark:border-brand-900 dark:bg-brand-950/40">
            <span class="flex items-center gap-2 text-xs font-medium text-brand-700 dark:text-brand-300">
                <svg class="size-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3" /><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                {{ $processLabel }}
            </span>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-brand-100 dark:bg-brand-900">
                <div class="upload-indeterminate h-full w-1/3 rounded-full bg-brand-500"></div>
            </div>
            <p class="mt-1.5 text-[11px] text-brand-700/70 dark:text-brand-300/70">File besar bisa butuh beberapa detik. Jangan tutup halaman ini.</p>
        </div>
    @endif
</div>
