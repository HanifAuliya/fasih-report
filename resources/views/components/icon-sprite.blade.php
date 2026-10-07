{{-- Semua ikon sekali per halaman; dipakai <x-icon> lewat <use href="#icon-…"> --}}
<svg xmlns="http://www.w3.org/2000/svg" class="hidden" aria-hidden="true">
    @foreach (\App\Support\Icons::PATHS as $iconName => $iconPaths)
        <symbol id="icon-{{ $iconName }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
            @foreach ($iconPaths as $d)
                <path d="{{ $d }}" />
            @endforeach
        </symbol>
    @endforeach
</svg>
