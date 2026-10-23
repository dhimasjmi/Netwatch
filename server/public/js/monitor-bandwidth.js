'use strict';

/** Halaman Bandwidth Monitoring: KPI, tren gabungan, dan tabel per perangkat (cari, urut, pagination, pilih beberapa). */
(() => {
    const main = document.querySelector('main[data-page="monitor-bandwidth"]');
    const UI = window.NWMon;
    if (!main || !UI) return;

    const $ = (id) => document.getElementById(id);
    const WINDOW_LABEL = { '1h': '1 Jam', '6h': '6 Jam', '24h': '24 Jam', '7d': '7 Hari' };
    const state = {
        window: UI.store.get('nw.bw.window', '1h'), group: '', q: '', sort: 'total', dir: 'desc',
        page: 1, perPage: Number(UI.store.get('nw.bw.perpage', '10')), only: false, selected: new Set(),
    };

    let lastRows = []; // id baris halaman ini, untuk "pilih semua di halaman"
    const refreshEl = $('mon-refresh');
    const windowEl = $('mon-window');
    const perPageEl = $('mon-perpage');
    if (![...windowEl.options].some((o) => o.value === state.window)) state.window = '1h';
    if (![...perPageEl.options].some((o) => Number(o.value) === state.perPage)) state.perPage = 10;
    windowEl.value = state.window;
    perPageEl.value = String(state.perPage);
    refreshEl.value = UI.store.get('nw.bw.refresh', '30');
    if (![...refreshEl.options].some((o) => o.value === refreshEl.value)) refreshEl.value = '30';

    const chart = UI.createAreaChart($('mon-chart'), {
        label: 'Tren bandwidth download dan upload',
        series: [{ key: 'rx', label: 'Download', className: 'series-rx' }, { key: 'tx', label: 'Upload', className: 'series-tx' }],
        format: UI.formatRate,
    });

    function params() {
        const p = new URLSearchParams({ window: state.window, sort: state.sort, dir: state.dir, page: String(state.page), per_page: String(state.perPage) });
        if (state.group) p.set('group', state.group);
        if (state.q) p.set('q', state.q);
        if (state.only && state.selected.size > 0) p.set('only', '1');
        state.selected.forEach((id) => p.append('devices[]', id));
        return p;
    }

    function setText(id, value) { $(id).textContent = value; }

    function renderRows(table) {
        const body = $('mon-rows');
        lastRows = table.rows.map((r) => r.id);
        body.replaceChildren();
        if (table.rows.length === 0) {
            const tr = UI.el('tr');
            const td = UI.el('td', 'muted', 'Tidak ada perangkat yang cocok.');
            td.colSpan = 10;
            tr.append(td);
            body.append(tr);
        }
        table.rows.forEach((row, i) => {
            const tr = UI.el('tr');
            const check = UI.el('input');
            check.type = 'checkbox';
            check.checked = state.selected.has(row.id);
            check.setAttribute('aria-label', `Pilih ${row.name}`);
            check.addEventListener('change', () => {
                if (check.checked) state.selected.add(row.id); else state.selected.delete(row.id);
                selectionChanged();
            });
            const name = UI.el('td');
            const link = UI.el('a', null, row.name);
            link.href = row.url;
            name.append(link);

            const usage = UI.el('progress', 'mon-usage');
            usage.max = 100; usage.value = row.usage_pct;
            usage.setAttribute('aria-label', `${row.usage_pct}% dari perangkat terbesar`);
            const share = UI.el('td', 'mon-usage-cell');
            share.append(usage, UI.el('span', null, `${row.usage_pct}%`));

            const status = UI.el('td');
            status.append(UI.el('span', `mon-pill ${row.online ? 'ok' : 'off'}`, row.online ? 'Online' : 'Offline'));

            const cells = [
                UI.el('td', 'col-check'), UI.el('td', null, String((table.page - 1) * table.per_page + i + 1)), name, UI.el('td', null, row.ip ?? '—'),
                UI.el('td', 'rate-rx', `↓ ${UI.formatRate(row.rx_bps)}`), UI.el('td', 'rate-tx', `↑ ${UI.formatRate(row.tx_bps)}`),
                UI.el('td', null, UI.formatBytes(row.total_bytes)), share, status, UI.el('td', 'muted', row.last_seen ? UI.formatClock(row.last_seen) : 'belum pernah'),
            ];
            cells[0].append(check);
            tr.append(...cells);
            body.append(tr);
        });

        const from = table.total === 0 ? 0 : (table.page - 1) * table.per_page + 1;
        setText('mon-showing', `Menampilkan ${UI.formatNumber(from)}–${UI.formatNumber(from + table.rows.length - (table.total === 0 ? 0 : 1))} dari ${UI.formatNumber(table.total)} perangkat`);
        UI.renderPager($('mon-pager'), table.page, table.last_page, (n) => { state.page = n; loader.load(); });

        const pageCheck = $('mon-page-check');
        pageCheck.checked = table.rows.length > 0 && table.rows.every((r) => state.selected.has(r.id));
        document.querySelectorAll('.mon-sort').forEach((b) => {
            const on = b.dataset.sort === state.sort;
            b.classList.toggle('is-sorted', on);
            b.setAttribute('aria-sort', on ? (state.dir === 'asc' ? 'ascending' : 'descending') : 'none');
            b.dataset.dir = on ? (state.dir === 'asc' ? '▲' : '▼') : '';
        });
    }

    function render(data) {
        const k = data.kpi;
        setText('kpi-devices', `${UI.formatNumber(k.devices_online)} / ${UI.formatNumber(k.devices_total)}`);
        setText('kpi-devices-sub', `${UI.formatNumber(k.devices_reporting)} melapor bandwidth`);
        setText('kpi-rx', UI.formatRate(k.rx_bps_now));
        setText('kpi-tx', UI.formatRate(k.tx_bps_now));
        setText('kpi-total', UI.formatBytes(k.total_rx_bytes + k.total_tx_bytes));
        setText('kpi-total-sub', `↓ ${UI.formatBytes(k.total_rx_bytes)} · ↑ ${UI.formatBytes(k.total_tx_bytes)}`);
        setText('chart-title', `Tren Bandwidth (${WINDOW_LABEL[data.window] ?? data.window} Terakhir)`);
        setText('chart-note', data.selected_count > 0 ? `Tren dihitung dari ${UI.formatNumber(data.selected_count)} perangkat terpilih.` : 'Tren dihitung dari semua perangkat pada filter ini.');
        chart.draw(data.trend, data.bucket_seconds);

        // Halaman bisa melewati batas terakhir setelah filter berubah; kembali ke halaman valid.
        if (data.table.page > data.table.last_page) { state.page = data.table.last_page; loader.load(); return; }
        renderRows(data.table);
        const note = $('refresh-note');
        note.textContent = `Diperbarui ${UI.formatClock(data.generated_at)}`;
        note.hidden = false;
    }

    const loader = UI.createLoader({
        url: main.dataset.url, params, render,
        onState: (s) => UI.setLive($('mon-live'), s),
        // Rentang panjang berat bagi database: refresh minimal 30 detik untuk ≥ 24 jam.
        minSecondsFor: () => (state.window === '24h' || state.window === '7d' ? 30 : 5),
    });

    function applyRefresh() {
        const seconds = loader.setSeconds(Number(refreshEl.value));
        if (seconds === 0) UI.setLive($('mon-live'), 'paused');
        if (Number(refreshEl.value) > 0 && seconds !== Number(refreshEl.value)) refreshEl.value = String(seconds);
        UI.store.set('nw.bw.refresh', refreshEl.value);
    }

    function selectionChanged() {
        const n = state.selected.size;
        setText('mon-selected', String(n));
        $('mon-clear').hidden = n === 0;
        if (n === 0 && state.only) { state.only = false; $('mon-only').checked = false; }
        state.page = 1;
        loader.load();
    }

    function reload() { state.page = 1; loader.load(); }

    refreshEl.addEventListener('change', applyRefresh);
    windowEl.addEventListener('change', () => { state.window = windowEl.value; UI.store.set('nw.bw.window', state.window); applyRefresh(); reload(); });
    $('mon-group').addEventListener('change', (e) => { state.group = e.target.value; reload(); });
    perPageEl.addEventListener('change', () => { state.perPage = Number(perPageEl.value); UI.store.set('nw.bw.perpage', state.perPage); reload(); });
    $('mon-search').addEventListener('input', UI.debounce((e) => { state.q = e.target.value.trim(); reload(); }, 300));
    $('mon-only').addEventListener('change', (e) => { state.only = e.target.checked; reload(); });
    $('mon-clear').addEventListener('click', () => { state.selected.clear(); selectionChanged(); });
    $('mon-page-check').addEventListener('change', (e) => {
        document.querySelectorAll('#mon-rows input[type="checkbox"]').forEach((box, i) => {
            const id = lastRows[i];
            if (id) { if (e.target.checked) state.selected.add(id); else state.selected.delete(id); }
        });
        selectionChanged();
    });
    document.querySelectorAll('.mon-sort').forEach((b) => b.addEventListener('click', () => {
        if (state.sort === b.dataset.sort) state.dir = state.dir === 'asc' ? 'desc' : 'asc';
        else { state.sort = b.dataset.sort; state.dir = b.dataset.sort === 'name' || b.dataset.sort === 'ip' ? 'asc' : 'desc'; }
        reload();
    }));

    applyRefresh();
    loader.load();
})();
