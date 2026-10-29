'use strict';

/**
 * Perilaku dashboard: polling status perangkat, tabel proses, dan pengiriman perintah.
 * Semua teks dari server dimasukkan lewat textContent (bukan innerHTML) agar aman dari XSS.
 */
(() => {
    // Bawaan 30 detik; halaman bisa menimpa lewat data-refresh-seconds (mis. halaman perangkat 5 detik agar hasil perintah cepat terlihat).
    const DEFAULT_REFRESH_SECONDS = 30;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const main = document.querySelector('main[data-page]');

    // ---------- utilitas ----------

    function showToast(message) {
        const toast = document.getElementById('toast');
        if (!toast) return;
        toast.textContent = message;
        toast.hidden = false;
        window.clearTimeout(showToast.timer);
        showToast.timer = window.setTimeout(() => { toast.hidden = true; }, 5000);
    }

    async function fetchJson(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
                ...(options.headers ?? {}),
            },
        });

        if (response.status === 401 || response.status === 419) {
            window.location.reload();
            throw new Error('Sesi berakhir.');
        }

        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
            const firstError = body.errors ? Object.values(body.errors).flat()[0] : null;
            throw new Error(firstError ?? body.message ?? `Permintaan gagal (${response.status}).`);
        }

        return body;
    }

    function formatUptime(totalSeconds) {
        const days = Math.floor(totalSeconds / 86400);
        const hours = Math.floor((totalSeconds % 86400) / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        if (days > 0) return `${days}h ${hours}j`;
        if (hours > 0) return `${hours}j ${minutes}m`;
        return `${minutes}m`;
    }

    const formatters = {
        percent: (d, key) => (d[key] == null ? '—' : `${Number(d[key]).toFixed(1)}%`),
        memory: (d) => (d.memory_total_mb ? `${(d.memory_used_mb / 1024).toFixed(1)} / ${(d.memory_total_mb / 1024).toFixed(1)} GB` : '—'),
        disk: (d) => (d.disk_total_gb ? `${d.disk_used_gb} / ${d.disk_total_gb} GB` : '—'),
        uptime: (d) => (d.uptime_seconds == null ? '—' : formatUptime(d.uptime_seconds)),
        text: (d, key) => d[key] ?? '—',
    };

    /** Terapkan data perangkat ke elemen bertanda data-field / data-bar / data-status di dalam root. */
    function applyDevice(root, device) {
        root.querySelectorAll('[data-field]').forEach((el) => {
            const format = formatters[el.dataset.format] ?? formatters.text;
            el.textContent = format(device, el.dataset.field);
        });

        root.querySelectorAll('[data-bar]').forEach((el) => {
            el.value = device[el.dataset.bar] ?? 0;
        });

        root.querySelectorAll('[data-status]').forEach((el) => {
            el.textContent = device.online ? 'Online' : 'Offline';
            el.classList.toggle('badge-ok', device.online);
            el.classList.toggle('badge-off', !device.online);
        });
    }

    function cell(text, className) {
        const td = document.createElement('td');
        td.textContent = text;
        if (className) td.className = className;
        return td;
    }

    function poll(task) {
        const run = async () => {
            if (document.hidden) return;
            try { await task(); } catch (error) { console.warn('Polling gagal:', error.message); }
        };
        run();
        const seconds = Math.max(2, Number(main?.dataset.refreshSeconds) || DEFAULT_REFRESH_SECONDS);
        window.setInterval(run, seconds * 1000);
    }

    // ---------- halaman daftar perangkat ----------

    function initDeviceIndex() {
        poll(async () => {
            const { data } = await fetchJson(main.dataset.liveUrl);
            data.forEach((device) => {
                const row = document.querySelector(`tr[data-device-id="${CSS.escape(device.id)}"]`);
                if (row) applyDevice(row, device);
            });
        });
    }

    // ---------- halaman detail perangkat ----------

    function initDeviceShow() {
        const canRun = main.dataset.canRun === '1';
        const tbody = document.querySelector('#process-table tbody');
        const filterInput = document.getElementById('process-filter');
        const snapshotLabel = document.getElementById('snapshot-label');
        const state = { processes: [], capturedAt: null, sortKey: 'memory_mb', sortDir: -1, filter: '' };

        function renderProcesses() {
            const needle = state.filter.toLowerCase();
            const rows = state.processes
                .filter((p) => needle === '' || p.name.toLowerCase().includes(needle) || String(p.pid) === needle)
                .sort((a, b) => {
                    const left = a[state.sortKey] ?? '';
                    const right = b[state.sortKey] ?? '';
                    const order = typeof left === 'number' && typeof right === 'number'
                        ? left - right
                        : String(left).localeCompare(String(right));
                    return order * state.sortDir;
                });

            const fragment = document.createDocumentFragment();
            rows.forEach((p) => {
                const tr = document.createElement('tr');
                tr.append(
                    cell(p.name),
                    cell(String(p.pid)),
                    cell(p.user ?? '—', 'muted'),
                    cell(p.cpu_seconds == null ? '—' : String(p.cpu_seconds)),
                    cell(p.memory_mb == null ? '—' : String(p.memory_mb)),
                );

                const actionCell = document.createElement('td');
                if (canRun) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'btn btn-small btn-danger';
                    button.textContent = 'Akhiri';
                    button.dataset.kill = String(p.pid);
                    button.dataset.name = p.name;
                    actionCell.append(button);
                }
                tr.append(actionCell);
                fragment.append(tr);
            });

            tbody.replaceChildren(fragment);
        }

        function renderCommands(commands) {
            const body = document.querySelector('#command-table tbody');
            const fragment = document.createDocumentFragment();
            commands.forEach((c) => {
                const tr = document.createElement('tr');
                const status = document.createElement('td');
                const badge = document.createElement('span');
                badge.className = `badge badge-${c.status}`;
                badge.textContent = c.status_label;
                status.append(badge);
                tr.append(cell(c.created_at ?? ''), cell(c.type), cell(c.requester ?? '—'), status, cell(c.error ?? '', 'muted'));
                fragment.append(tr);
            });
            body.replaceChildren(fragment);
        }

        async function refresh() {
            const data = await fetchJson(main.dataset.liveUrl);
            applyDevice(main, data.device);
            renderCommands(data.commands);

            if (data.snapshot && data.snapshot.captured_at !== state.capturedAt) {
                state.capturedAt = data.snapshot.captured_at;
                state.processes = data.snapshot.processes;
                snapshotLabel.textContent = `Data per ${data.snapshot.captured_label}`;
                renderProcesses();
            }
        }

        async function sendCommand(body, successMessage) {
            try {
                await fetchJson(main.dataset.commandUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body),
                });
                showToast(successMessage);
                await refresh();
            } catch (error) {
                showToast(error.message);
            }
        }

        // Tombol perintah di header halaman.
        document.querySelectorAll('[data-command]').forEach((button) => {
            button.addEventListener('click', () => {
                const type = button.dataset.command;

                if (type === 'send_message') {
                    const message = window.prompt('Pesan untuk ditampilkan di komputer user (maks. 500 karakter):');
                    if (message && message.trim() !== '') {
                        sendCommand({ type, message: message.trim() }, 'Pesan dijadwalkan.');
                    }
                    return;
                }

                if (type === 'restart_computer' || type === 'shutdown_computer') {
                    const label = type === 'restart_computer' ? 'merestart' : 'mematikan';
                    if (!window.confirm(`Yakin ingin ${label} komputer ini? Pengguna diberi waktu 60 detik.`)) return;
                }

                sendCommand({ type }, 'Perintah dijadwalkan.');
            });
        });

        // Tombol "Akhiri" di tabel proses (delegasi event karena baris dibuat ulang).
        tbody.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-kill]');
            if (!button) return;

            const { kill: pid, name } = button.dataset;
            if (!window.confirm(`Akhiri proses "${name}" (PID ${pid})? Data yang belum disimpan pengguna dapat hilang.`)) return;

            sendCommand({ type: 'terminate_process', pid: Number(pid), process_name: name }, `Perintah akhiri "${name}" dijadwalkan.`);
        });

        filterInput.addEventListener('input', () => {
            state.filter = filterInput.value.trim();
            renderProcesses();
        });

        document.querySelectorAll('#process-table th[data-sort]').forEach((th) => {
            th.addEventListener('click', () => {
                const key = th.dataset.sort;
                state.sortDir = state.sortKey === key ? -state.sortDir : 1;
                state.sortKey = key;
                renderProcesses();
            });
        });

        poll(refresh);
    }

    // ---------- inisialisasi ----------

    document.addEventListener('submit', (event) => {
        const message = event.target.dataset?.confirm;
        if (message && !window.confirm(message)) event.preventDefault();
    });

    // Menu dropdown (Monitoring, Administrasi): hanya satu terbuka; menutup saat klik di luar atau tekan Esc.
    const navMenus = [...document.querySelectorAll('details.nav-menu')];
    if (navMenus.length > 0) {
        const closeAll = (except = null) => navMenus.forEach((m) => { if (m !== except) m.open = false; });
        navMenus.forEach((menu) => menu.addEventListener('toggle', () => { if (menu.open) closeAll(menu); }));
        document.addEventListener('click', (e) => { navMenus.forEach((m) => { if (!m.contains(e.target)) m.open = false; }); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAll(); });
    }

    // Bungkus tabel agar layar sempit atau zoom besar menggulir ke samping, bukan melebar melewati halaman.
    // Tabel dengan lima kolom atau lebih diberi lebar minimum (kelas table-wide) supaya tidak diperas.
    function wrapTables(root) {
        root.querySelectorAll('table.table').forEach((table) => {
            if (table.closest('.table-wrap, .table-scroll, .mon-table-wrap')) return;
            const wrap = document.createElement('div');
            wrap.className = 'table-wrap';
            table.replaceWith(wrap);
            wrap.append(table);
            if (table.querySelectorAll('thead th').length >= 5) table.classList.add('table-wide');
        });
    }
    wrapTables(document);


    // Ambil ulang halaman ini dan ganti isi <main> (HTML dari server sendiri; diurai sebagai DOM, bukan innerHTML).
    async function refreshInPlace(current) {
        try {
            const response = await fetch(window.location.href, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
            if (!response.ok || response.redirected) { window.location.reload(); return; }
            const next = new DOMParser().parseFromString(await response.text(), 'text/html').getElementById('content');
            if (next) {
                // Bagian bertanda [data-no-dirty] (pengaturan alert suara) dipertahankan apa adanya agar fokus,
                // pilihan, dan keadaan terbukanya tidak hilang tiap pembaruan.
                const kept = [...current.querySelectorAll('[data-no-dirty]')];
                current.replaceChildren(...[...next.childNodes].map((n) => document.importNode(n, true)));
                const fresh = [...current.querySelectorAll('[data-no-dirty]')];
                if (kept.length === fresh.length) fresh.forEach((node, i) => node.replaceWith(kept[i]));
                wrapTables(current);
                document.dispatchEvent(new CustomEvent('nw:refreshed'));
            }
        } catch { /* coba lagi pada siklus berikutnya */ }
    }

    if (main?.dataset.page === 'devices-index') initDeviceIndex();
    if (main?.dataset.page === 'devices-show') initDeviceShow();
    // Halaman server-rendered bertanda data-autoreload (dashboard, event, blokir) dimuat ulang berkala,
    // hanya saat tab terlihat dan pengguna tidak sedang mengisi formulir (agar ketikan tidak hilang).
    if (main?.hasAttribute('data-autoreload')) {
        const seconds = Math.max(10, Number(main.dataset.refreshSeconds) || DEFAULT_REFRESH_SECONDS);
        let dirty = false;
        // Kolom di dalam [data-no-dirty] (mis. pengaturan alert suara) tidak menunda pembaruan otomatis.
        main.addEventListener('input', (e) => { if (!e.target.closest('[data-no-dirty]')) dirty = true; });

        const note = document.getElementById('refresh-note');
        if (note) {
            note.textContent = `Diperbarui ${new Date().toLocaleTimeString('id-ID')} · otomatis tiap ${seconds} detik`;
            note.hidden = false;
        }

        const isEditing = () => dirty || (['INPUT', 'SELECT', 'TEXTAREA'].includes(document.activeElement?.tagName ?? '') && !document.activeElement.closest('[data-no-dirty]'));
        window.setInterval(() => {
            // Halaman dengan data-refresh-hidden (Jaringan saat alert suara aktif) tetap diperbarui di tab latar belakang.
            if (document.hidden && !main.hasAttribute('data-refresh-hidden')) return;
            if (isEditing()) {
                if (note) note.textContent = 'Pembaruan otomatis ditunda karena Anda sedang mengisi formulir.';
                return;
            }
            // Reload menutup layar penuh dan mereset izin suara browser; saat mode monitor (kiosk) atau pada halaman
            // bertanda data-inplace (Jaringan), isi halaman diganti tanpa reload.
            if (document.documentElement.classList.contains('kiosk') || main.hasAttribute('data-inplace')) refreshInPlace(main);
            else window.location.reload();
        }, seconds * 1000);
    }
})();
