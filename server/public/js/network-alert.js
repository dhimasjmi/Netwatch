'use strict';

/**
 * Alert suara di halaman Jaringan: berbunyi di browser ini saat ada target yang down.
 * - Pengaturan disimpan di localStorage browser ini (tiap komputer/TV monitor diatur sendiri).
 * - Nada dibuat dengan Web Audio (tanpa berkas suara); nama perangkat bisa diucapkan lewat speechSynthesis.
 * - Browser baru mengizinkan suara setelah pengguna berinteraksi dengan halaman, jadi satu klik tetap dibutuhkan.
 * - Daftar target down dibaca dari #noc-down (dikirim server, tidak terpengaruh filter halaman).
 * Teks dari server hanya masuk lewat textContent atau ucapan, tidak pernah innerHTML.
 */
(() => {
    const main = document.querySelector('main[data-page="network"]');
    if (!main) return;

    const KEY = 'nw.noc.sound';
    const DEFAULTS = { enabled: false, trigger: 'offline', tone: 'siren', repeat: 60, volume: 70, speak: false };
    const CHOICES = { trigger: ['offline', 'both'], tone: ['siren', 'beep', 'bell'], repeat: [0, 30, 60, 300] };
    const TICK_MS = 5000;
    const baseTitle = document.title;

    function sanitize(raw) {
        const cfg = { ...DEFAULTS };
        if (raw === null || typeof raw !== 'object') return cfg;
        cfg.enabled = raw.enabled === true;
        cfg.speak = raw.speak === true;
        if (CHOICES.trigger.includes(raw.trigger)) cfg.trigger = raw.trigger;
        if (CHOICES.tone.includes(raw.tone)) cfg.tone = raw.tone;
        if (CHOICES.repeat.includes(Number(raw.repeat))) cfg.repeat = Number(raw.repeat);
        const volume = Number(raw.volume);
        if (Number.isFinite(volume)) cfg.volume = Math.min(100, Math.max(10, Math.round(volume)));
        return cfg;
    }

    function load() {
        try { return sanitize(JSON.parse(window.localStorage.getItem(KEY) ?? 'null')); } catch { return { ...DEFAULTS }; }
    }

    function save() {
        try { window.localStorage.setItem(KEY, JSON.stringify(cfg)); } catch { /* mode privat: pengaturan berlaku sampai halaman ditutup */ }
    }

    let cfg = load();

    // ---------- suara ----------

    let ctx = null;

    /** Dibuat/dilanjutkan hanya dari gestur pengguna (klik/tombol), sesuai aturan autoplay browser. */
    function audio() {
        if (ctx === null) {
            const Context = window.AudioContext || window.webkitAudioContext;
            if (!Context) return null;
            try { ctx = new Context(); } catch { return null; }
        }
        if (ctx.state === 'suspended') ctx.resume().then(tick).catch(() => {});
        return ctx;
    }

    const audioRunning = () => ctx !== null && ctx.state === 'running';

    function blip(c, start, freq, seconds, type, peak) {
        const osc = c.createOscillator();
        const gain = c.createGain();
        osc.type = type;
        osc.frequency.value = freq;
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(peak, start + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + seconds);
        osc.connect(gain);
        gain.connect(c.destination);
        osc.start(start);
        osc.stop(start + seconds + 0.05);
    }

    // Setiap nada mengembalikan durasinya (detik) supaya ucapan menunggu nada selesai.
    const TONES = {
        siren: (c, t, v) => { for (let i = 0; i < 4; i++) blip(c, t + i * 0.4, i % 2 ? 880 : 660, 0.38, 'square', v * 0.5); return 1.6; },
        beep: (c, t, v) => { for (let i = 0; i < 3; i++) blip(c, t + i * 0.28, 1000, 0.16, 'sine', v); return 0.85; },
        bell: (c, t, v) => { for (let i = 0; i < 2; i++) { blip(c, t + i * 0.9, 1175, 0.9, 'sine', v); blip(c, t + i * 0.9, 2350, 0.5, 'sine', v * 0.4); } return 1.8; },
    };

    function speak(items, delaySeconds) {
        if (!cfg.speak || !('speechSynthesis' in window) || typeof SpeechSynthesisUtterance === 'undefined') return;
        const names = items.slice(0, 3).map((t) => t.name).join(', ');
        const rest = items.length > 3 ? ` dan ${items.length - 3} lainnya` : '';
        const allDown = items.every((t) => t.status === 'offline');
        const utterance = new SpeechSynthesisUtterance(`Perhatian. Perangkat jaringan ${allDown ? 'down' : 'bermasalah'}: ${names}${rest}.`);
        utterance.lang = 'id-ID';
        utterance.volume = cfg.volume / 100;
        window.setTimeout(() => {
            try { window.speechSynthesis.cancel(); window.speechSynthesis.speak(utterance); } catch { /* diabaikan */ }
        }, delaySeconds * 1000);
    }

    /** Mengembalikan false bila browser masih memblokir suara (belum ada interaksi pengguna). */
    function play(items) {
        if (!audioRunning()) return false;
        const seconds = TONES[cfg.tone](ctx, ctx.currentTime + 0.05, (cfg.volume / 100) * 0.5);
        speak(items, seconds + 0.2);
        return true;
    }

    // ---------- logika alarm ----------

    let known = null; // id target yang sudah dibunyikan (null = belum pernah dievaluasi)
    let lastPlay = 0;

    function readDown() {
        try {
            const list = JSON.parse(document.getElementById('noc-down')?.dataset.down ?? '[]');
            return Array.isArray(list) ? list.filter((t) => t && typeof t.name === 'string') : [];
        } catch { return []; }
    }

    function tick() {
        const all = readDown();
        const offline = all.filter((t) => t.status === 'offline').length;
        document.title = offline > 0 ? `(${offline} DOWN) ${baseTitle}` : baseTitle;

        const list = all.filter((t) => t.status === 'offline' || (cfg.trigger === 'both' && t.status === 'warning'));
        syncUi(list.length);

        if (!cfg.enabled) {
            known = new Set(list.map((t) => t.id));
            return;
        }

        const fresh = list.filter((t) => known === null || !known.has(t.id));
        const now = Date.now();
        let played = true;
        if (fresh.length > 0) {
            played = play(fresh);
            if (played) lastPlay = now;
        } else if (list.length > 0 && cfg.repeat > 0 && now - lastPlay >= cfg.repeat * 1000) {
            if (play(list)) lastPlay = now;
        }
        // Target yang belum sempat dibunyikan (browser masih memblokir) tetap dianggap baru pada siklus berikut.
        known = new Set(list.filter((t) => (known !== null && known.has(t.id)) || played).map((t) => t.id));
    }

    // ---------- tampilan kontrol ----------

    function syncUi(activeCount) {
        document.querySelectorAll('[data-noc-sound-toggle]').forEach((button) => {
            button.textContent = cfg.enabled ? '🔔 Suara aktif' : '🔕 Suara mati';
            button.setAttribute('aria-pressed', String(cfg.enabled));
            button.classList.toggle('is-on', cfg.enabled);
            button.classList.toggle('is-alarm', cfg.enabled && activeCount > 0);
        });

        document.querySelectorAll('[data-noc-sound]').forEach((input) => {
            const key = input.dataset.nocSound;
            if (input.type === 'checkbox') input.checked = cfg[key] === true;
            else if (String(cfg[key]) !== input.value) input.value = String(cfg[key]);
        });
        document.querySelectorAll('[data-noc-sound-volume]').forEach((out) => { out.textContent = `${cfg.volume}%`; });

        let note = '';
        if (cfg.enabled && !audioRunning()) note = 'Menunggu satu klik di halaman ini sebelum browser mengizinkan suara.';
        else if (cfg.enabled) note = 'Alert aktif. Biarkan halaman ini tetap terbuka (boleh di tab atau jendela terpisah).';
        if (cfg.speak && !('speechSynthesis' in window)) note = 'Browser ini tidak mendukung suara ucapan.';
        document.querySelectorAll('[data-noc-sound-note]').forEach((el) => { el.textContent = note; });

        // Saat alert aktif, halaman tetap diperbarui walau tab tidak sedang dilihat (dibaca netwatch.js).
        main.toggleAttribute('data-refresh-hidden', cfg.enabled);
    }

    function update(patch) {
        cfg = sanitize({ ...cfg, ...patch });
        save();
        if (cfg.enabled) audio();
        tick();
    }

    // Kontrol di-render ulang saat halaman diperbarui di tempat, jadi pakai delegasi event.
    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) return;
        if (event.target.closest('[data-noc-sound-toggle]')) { update({ enabled: !cfg.enabled }); return; }
        if (event.target.closest('[data-noc-sound-test]')) {
            const c = audio();
            if (c === null) { syncUi(0); document.querySelectorAll('[data-noc-sound-note]').forEach((el) => { el.textContent = 'Browser ini tidak mendukung audio.'; }); return; }
            const sample = () => play([{ id: 0, name: 'Contoh perangkat', status: 'offline' }]);
            if (audioRunning()) sample(); else c.resume().then(sample).catch(() => {});
        }
    });

    document.addEventListener('change', (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement || input instanceof HTMLSelectElement) || input.dataset.nocSound === undefined) return;
        update({ [input.dataset.nocSound]: input.type === 'checkbox' ? input.checked : input.value });
    });
    document.addEventListener('input', (event) => {
        const input = event.target;
        if (input instanceof HTMLInputElement && input.type === 'range' && input.dataset.nocSound === 'volume') update({ volume: input.value });
    });

    // Gestur apa pun membuka kunci audio bila alert aktif (mis. setelah halaman dibuka ulang).
    ['pointerdown', 'keydown'].forEach((name) => document.addEventListener(name, () => { if (cfg.enabled) audio(); }, { passive: true }));
    document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
    document.addEventListener('nw:refreshed', tick);

    tick();
    window.setInterval(tick, TICK_MS);
})();
