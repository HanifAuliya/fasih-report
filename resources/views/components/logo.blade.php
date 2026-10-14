{{-- Logo FASIH Report: cincin progres yang hampir penuh + tanda centang (pelacak tindak lanjut). SVG inline, tanpa permintaan ke server. --}}
@php $gradient = 'logo-'.\Illuminate\Support\Str::random(6); @endphp
<svg viewBox="0 0 32 32" fill="none" aria-hidden="true" {{ $attributes }}>
    <defs>
        <linearGradient id="{{ $gradient }}" x1="3" y1="1" x2="29" y2="31" gradientUnits="userSpaceOnUse">
            <stop stop-color="#8d8af0" />
            <stop offset="1" stop-color="#4b4ab8" />
        </linearGradient>
    </defs>
    <rect width="32" height="32" rx="9" fill="url(#{{ $gradient }})" />
    <circle cx="16" cy="16" r="8.5" stroke="#fff" stroke-opacity=".28" stroke-width="2.6" />
    <path class="logo-arc" d="M16 7.5A8.5 8.5 0 1 1 7.5 16" stroke="#fff" stroke-width="2.6" stroke-linecap="round" />
    <path d="m12.4 16.3 2.6 2.6 4.8-5.1" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" />
</svg>
