@props(['value' => 0, 'size' => 'md'])

@php
    $value = max(0, min(100, (int) $value));
    // Satu warna aksen untuk yang sedang berjalan; hijau hanya saat benar-benar selesai
    $fill = $value >= 100 ? 'bg-emerald-500' : 'bg-brand-600';
    $height = $size === 'sm' ? 'h-1.5' : 'h-2';
@endphp

<div {{ $attributes->merge(['class' => 'w-full overflow-hidden rounded-full bg-slate-200/70 '.$height]) }}
    role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $value }}">
    <div class="{{ $height }} {{ $fill }} rounded-full transition-[width] duration-500" style="width: {{ $value }}%"></div>
</div>
