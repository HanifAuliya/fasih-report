{{--
    Layar memuat: muncul saat halaman pertama dimuat atau saat pindah halaman (wire:navigate), hanya bila
    prosesnya lebih dari ±0,25 detik (lihat .app-loader di app.css). Murni CSS + sedikit JS di browser.
--}}
<div class="app-loader" role="status" aria-live="polite">
    <div class="app-loader__card">
        <x-logo class="app-loader__logo" />
        <p class="app-loader__text">Memuat…</p>
        <span class="app-loader__bar"></span>
    </div>
</div>
<script>
    // Cadangan bila app.js gagal dimuat: layar memuat tidak boleh menutup halaman selamanya
    document.addEventListener('DOMContentLoaded', () => document.documentElement.classList.add('app-ready'));
    setTimeout(() => document.documentElement.classList.add('app-ready'), 8000);
</script>
