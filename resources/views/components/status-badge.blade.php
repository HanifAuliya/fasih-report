@props(['status'])

@php
    $styles = [
        'belum' => 'bg-slate-100 text-slate-600',
        'proses' => 'bg-amber-50 text-amber-700 ring-1 ring-amber-600/20',
        'selesai' => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-600/20',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'badge '.($styles[$status] ?? $styles['belum'])]) }}>
    <span class="size-1.5 rounded-full bg-current opacity-70"></span>
    {{ \App\Models\Kecamatan::STATUSES[$status] ?? ucfirst($status) }}
</span>
