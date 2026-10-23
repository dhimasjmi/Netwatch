'use strict';

/**
 * Mode monitor (layar penuh) untuk halaman Monitoring: menyembunyikan menu/kontrol dan memperbesar tampilan.
 * Memakai Fullscreen API bila ada; ?tv=1 membuka halaman langsung dalam tampilan monitor (tanpa layar penuh,
 * karena browser mewajibkan klik pengguna untuk layar penuh).
 */
(() => {
    const root = document.documentElement;

    const setKiosk = (on) => {
        root.classList.toggle('kiosk', on);
        document.querySelectorAll('[data-fullscreen-toggle]').forEach((b) => {
            b.setAttribute('aria-pressed', String(on));
            b.textContent = on ? 'Keluar layar penuh' : 'Layar penuh';
        });
    };

    async function toggle() {
        const on = !root.classList.contains('kiosk');
        setKiosk(on);
        try {
            if (on && !document.fullscreenElement && root.requestFullscreen) await root.requestFullscreen();
            if (!on && document.fullscreenElement) await document.exitFullscreen();
        } catch { /* ditolak browser: tampilan monitor tetap aktif tanpa layar penuh */ }
    }

    // Tombol ada di dalam <main> yang bisa diganti saat auto-refresh, jadi pakai delegasi.
    document.addEventListener('click', (e) => {
        if (e.target instanceof Element && e.target.closest('[data-fullscreen-toggle]')) toggle();
    });

    // Esc keluar dari layar penuh bawaan browser: ikut keluar dari tampilan monitor.
    document.addEventListener('fullscreenchange', () => { if (!document.fullscreenElement) setKiosk(false); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && root.classList.contains('kiosk') && !document.fullscreenElement) setKiosk(false); });

    if (new URLSearchParams(window.location.search).get('tv') === '1') setKiosk(true);
})();
