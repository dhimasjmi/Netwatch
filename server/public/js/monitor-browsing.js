'use strict';

/** Halaman Browsing Monitoring: KPI, tren akses, situs teratas, dan tabel aktivitas berpagination. */
(() => {
    const main = document.querySelector('main[data-page="monitor-browsing"]');
    const UI = window.NWMon;
    if (!main || !UI) return;

    const $ = (id) => document.getElementById(id);
    const WINDOW_LABEL = { '1h': '1 Jam', '6h': '6 Jam', '24h': '24 Jam', '7d': '7 Hari', '30d': '30 Hari' };
    const state = { window: UI.store.get('nw.br.window', '1h'), device: main.dataset.device || '', kind: '', q: '', page: 1, perPage: Number(UI.store.get('nw.br.perpage', '10')) };

    const refreshEl = $('mon-refresh');
    const windowEl = $('mon-window');
    const perPageEl = $('mon-perpage');
    if (!(state.window in WINDOW_LABEL)) state.window = '1h';
    if (![...perPageEl.options].some((o) => Number(o.value) === state.perPage)) state.perPage = 10;
    windowEl.value = state.window;
    perPageEl.value = String(state.perPage);
    refreshEl.value = UI.store.get('nw.br.refresh', '30');
    if (![...refreshEl.options].some((o) => o.value === refreshEl.value)) refreshEl.value = '30';

    const chart = UI.createAreaChart($('mon-chart'), {
        label: 'Tren akses situs dan koneksi',
        series: [{ key: 'url', label: 'Situs', className: 'series-rx' }, { key: 'conn', label: 'Koneksi', className: 'series-tx' }],
        format: (v) => UI.formatNumber(Math.round(v)),
        axisFormat: (v) => UI.formatCompact(Math.round(v)),
    });

    function params() {
        const p = new URLSearchParams({ window: state.window, page: String(state.page), per_page: String(state.perPage) });
        ['device', 'kind', 'q'].forEach((k) => { if (state[k]) p.set(k, state[k]); });
        return p;
    }

    function renderTop(sites) {
        const list = $('mon-top');
        list.replaceChildren();
        if (sites.length === 0) { list.append(UI.el('li', 'muted', 'Belum ada data.')); return; }
        sites.forEach((s) => {
            const li = UI.el('li');
            const bar = UI.el('progress', 'mon-usage');
            bar.max = 100; bar.value = s.share_pct;
            bar.setAttribute('aria-label', `${s.share_pct}%`);
            li.append(UI.el('span', 'mon-top-host', s.host), bar, UI.el('span', 'mon-top-pct', `${s.share_pct.toFixed(1)}%`));
            li.title = `${UI.formatNumber(s.total)} akses · ${UI.formatNumber(s.devices)} perangkat`;
            list.append(li);
        });
    }

    function renderRows(table) {
        const body = $('mon-rows');
        body.replaceChildren();
        if (table.rows.length === 0) {
            const tr = UI.el('tr');
            const td = UI.el('td', 'muted', 'Tidak ada aktivitas pada rentang dan filter ini.');
            td.colSpan = 8;
            tr.append(td);
            body.append(tr);
        }
        table.rows.forEach((row, i) => {
            const tr = UI.el('tr');
            const kind = UI.el('td');
            kind.append(UI.el('span', `mon-pill ${row.kind === 'url' ? 'ok' : 'info'}`, row.kind === 'url' ? 'Situs' : 'Koneksi'));
            const site = UI.el('td', 'mon-site', row.host || row.ip || '—');
            if (row.url) site.title = row.url;
            tr.append(
                UI.el('td', null, String((table.page - 1) * table.per_page + i + 1)), UI.el('td', null, UI.formatDateTime(row.time)),
                UI.el('td', null, row.device), UI.el('td', null, row.device_ip ?? '—'), site, kind,
                UI.el('td', 'muted', row.app ?? '—'), UI.el('td', null, row.ip ? (row.port ? `${row.ip}:${row.port}` : row.ip) : '—'),
            );
            body.append(tr);
        });

        const from = table.total === 0 ? 0 : (table.page - 1) * table.per_page + 1;
        const total = `${UI.formatNumber(table.total)}${table.total_capped ? '+' : ''}`;
        $('mon-showing').textContent = `Menampilkan ${UI.formatNumber(from)}–${UI.formatNumber(table.total === 0 ? 0 : from + table.rows.length - 1)} dari ${total} aktivitas`;
        UI.renderPager($('mon-pager'), table.page, table.last_page, (n) => { state.page = n; loader.load(); });
    }

    function render(data) {
        const k = data.kpi;
        if (main.dataset.device) {
            $('kpi-active').textContent = k.devices_active > 0 ? 'Ada' : 'Tidak ada';
            $('kpi-active-sub').textContent = 'pada rentang ini';
        } else {
            $('kpi-active').textContent = `${UI.formatNumber(k.devices_active)} / ${UI.formatNumber(k.devices_total)}`;
            $('kpi-active-sub').textContent = 'perangkat dengan aktivitas';
        }
        $('kpi-total').textContent = UI.formatCompact(k.total);
        $('kpi-sites').textContent = UI.formatNumber(k.unique_sites);
        $('kpi-top').textContent = k.top_site ?? '—';
        $('chart-note').textContent = `Tren ${WINDOW_LABEL[data.window] ?? data.window} terakhir${data.trend.bucket_seconds >= 86400 ? ' (per hari; rincian lebih dari ' + main.dataset.rawDays + ' hari sudah dirangkum)' : ''}.`;
        chart.draw(data.trend.points, data.trend.bucket_seconds);
        renderTop(data.top_sites);

        if (data.table.page > data.table.last_page) { state.page = data.table.last_page; loader.load(); return; }
        renderRows(data.table);
        const note = $('refresh-note');
        note.textContent = `Diperbarui ${UI.formatClock(data.generated_at)}`;
        note.hidden = false;
    }

    const loader = UI.createLoader({
        url: main.dataset.url, params, render,
        onState: (s) => UI.setLive($('mon-live'), s),
        minSecondsFor: () => (state.window === '24h' || state.window === '7d' || state.window === '30d' ? 30 : 5),
    });

    function applyRefresh() {
        const seconds = loader.setSeconds(Number(refreshEl.value));
        if (seconds === 0) UI.setLive($('mon-live'), 'paused');
        if (Number(refreshEl.value) > 0 && seconds !== Number(refreshEl.value)) refreshEl.value = String(seconds);
        UI.store.set('nw.br.refresh', refreshEl.value);
    }

    const reload = () => { state.page = 1; loader.load(); };
    refreshEl.addEventListener('change', applyRefresh);
    windowEl.addEventListener('change', () => { state.window = windowEl.value; UI.store.set('nw.br.window', state.window); applyRefresh(); reload(); });
    perPageEl.addEventListener('change', () => { state.perPage = Number(perPageEl.value); UI.store.set('nw.br.perpage', state.perPage); reload(); });
    // Pada halaman per perangkat tidak ada pilihan perangkat: perangkatnya tetap.
    $('mon-device')?.addEventListener('change', (e) => { state.device = e.target.value; reload(); });
    $('mon-kind').addEventListener('change', (e) => { state.kind = e.target.value; reload(); });
    $('mon-search').addEventListener('input', UI.debounce((e) => { state.q = e.target.value.trim(); reload(); }, 300));

    applyRefresh();
    loader.load();
})();
