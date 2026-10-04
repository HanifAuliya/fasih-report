{{-- Pasang tema & lebar sidebar sebelum halaman tampil supaya tidak berkedip / melompat --}}
<script>
    window.applyShellPrefs = () => {
        let mode = 'system';
        let collapsed = false;
        try {
            mode = localStorage.getItem('theme') || 'system';
            collapsed = localStorage.getItem('sidebar-collapsed') === '1';
        } catch (e) {}
        const dark = mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', dark);
        document.documentElement.classList.toggle('sidebar-collapsed', collapsed);
    };
    window.applyShellPrefs();
</script>
