@props(['name', 'class' => 'size-5'])

{{-- Merujuk simbol di sprite (<x-icon-sprite />, sekali per halaman) --}}
<svg {{ $attributes->merge(['class' => $class]) }} aria-hidden="true"><use href="#icon-{{ $name }}" /></svg>
