'use strict';

/**
 * Komponen bersama halaman Monitoring (Bandwidth & Browsing): formatter, grafik area SVG interaktif,
 * pager bernomor, polling dengan auto-refresh, dan penyimpanan preferensi.
 * Semua teks dari server dimasukkan lewat textContent (tanpa innerHTML) agar aman dari XSS.
 */
window.NWMon = (() => {
    const SVG_NS = 'http://www.w3.org/2000/svg';
    let uid = 0;

    const nf = new Intl.NumberFormat('id-ID');

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    function svg(tag, attrs = {}) {
        const node = document.createElementNS(SVG_NS, tag);
        Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, String(v)));
        return node;
    }

    function scaled(value, units, base) {
        let v = value;
        let i = 0;
        while (v >= base && i < units.length - 1) { v /= base; i++; }
        return `${v >= 100 || i === 0 ? Math.round(v) : v.toFixed(1)} ${units[i]}`;
    }

    const formatRate = (bps) => scaled(Number(bps) || 0, ['bps', 'Kbps', 'Mbps', 'Gbps'], 1000);
    const formatBytes = (bytes) => scaled(Number(bytes) || 0, ['B', 'KB', 'MB', 'GB', 'TB'], 1024);
    const formatNumber = (n) => nf.format(Number(n) || 0);

    function formatCompact(n) {
        const v = Number(n) || 0;
        if (v >= 1e6) return `${(v / 1e6).toFixed(1)} jt`;
        if (v >= 1e4) return `${(v / 1e3).toFixed(1)} rb`;
        return nf.format(v);
    }

    function formatClock(ts, withSeconds = true) {
        if (!ts) return '—';
        return new Date(ts * 1000).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', ...(withSeconds ? { second: '2-digit' } : {}) }).replace(/\./g, ':');
    }

    function formatAxisTime(ts, bucket) {
        const d = new Date(ts * 1000);
        if (bucket >= 86400) return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short' });
        return d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }).replace(/\./g, ':');
    }

    function formatDateTime(ts) {
        return new Date(ts * 1000).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit' }).replace(/\./g, ':');
    }

    function debounce(fn, ms) {
        let t = 0;
        return (...args) => { window.clearTimeout(t); t = window.setTimeout(() => fn(...args), ms); };
    }

    // localStorage bisa tidak tersedia/ditolak (mode privat); halaman tetap harus berfungsi.
    const store = {
        get(key, fallback) {
            try { const v = window.localStorage.getItem(key); return v === null ? fallback : v; } catch { return fallback; }
        },
        set(key, value) {
            try { window.localStorage.setItem(key, String(value)); } catch { /* diabaikan */ }
        },
    };

    function niceMax(value) {
        if (value <= 0) return 1;
        const pow = 10 ** Math.floor(Math.log10(value));
        const n = value / pow;
        return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * pow;
    }

    /**
     * Grafik area dua seri dengan grid, sumbu, dan tooltip saat kursor/sentuhan di atas grafik.
     * opts: { series:[{key,label,className}], format(value), axisFormat(value) }
     */
    function createAreaChart(host, opts) {
        const W = 900;
        const H = 260;
        const pad = { left: 62, right: 14, top: 12, bottom: 26 };
        const id = `mon-g${++uid}`;
        const [a, b] = opts.series;

        host.replaceChildren();
        const root = svg('svg', { viewBox: `0 0 ${W} ${H}`, class: 'mon-chart', role: 'img', preserveAspectRatio: 'none', 'aria-label': opts.label || 'Grafik tren' });
        const defs = svg('defs');
        opts.series.forEach((s, i) => {
            const g = svg('linearGradient', { id: `${id}-${i}`, x1: 0, y1: 0, x2: 0, y2: 1 });
            g.append(svg('stop', { offset: '0%', class: `stop-top ${s.className}` }), svg('stop', { offset: '100%', class: `stop-bottom ${s.className}` }));
            defs.append(g);
        });
        const grid = svg('g');
        const areas = svg('g');
        const lines = svg('g');
        const hoverLine = svg('line', { class: 'mon-hover-line', y1: pad.top, y2: H - pad.bottom, visibility: 'hidden' });
        const dots = opts.series.map((s) => svg('circle', { r: 4, class: `mon-dot ${s.className}`, visibility: 'hidden' }));
        const overlay = svg('rect', { x: pad.left, y: pad.top, width: W - pad.left - pad.right, height: H - pad.top - pad.bottom, class: 'mon-overlay' });
        root.append(defs, grid, areas, lines, hoverLine, ...dots, overlay);

        const tip = el('div', 'mon-tip');
        tip.hidden = true;
        const empty = el('div', 'mon-chart-empty', 'Belum ada data pada rentang ini.');
        empty.hidden = true;
        const wrap = el('div', 'mon-chart-wrap');
        wrap.append(root, tip, empty);
        host.append(wrap);

        let state = { points: [], bucket: 0, xs: [] };

        function draw(points, bucket) {
            grid.replaceChildren(); areas.replaceChildren(); lines.replaceChildren();
            hoverLine.setAttribute('visibility', 'hidden'); dots.forEach((d) => d.setAttribute('visibility', 'hidden')); tip.hidden = true;
            empty.hidden = points.length > 0;
            root.classList.toggle('is-empty', points.length === 0);
            state = { points, bucket, xs: [] };

            const max = niceMax(Math.max(0, ...points.flatMap((p) => [p[1], p[2]])));
            const y = (v) => pad.top + (1 - v / max) * (H - pad.top - pad.bottom);
            for (let i = 0; i <= 4; i++) {
                const v = (max * i) / 4;
                const yy = y(v);
                grid.append(svg('line', { x1: pad.left, x2: W - pad.right, y1: yy, y2: yy, class: 'mon-grid' }));
                const t = svg('text', { x: pad.left - 8, y: yy + 4, class: 'mon-axis', 'text-anchor': 'end' });
                t.textContent = (opts.axisFormat || opts.format)(v);
                grid.append(t);
            }
            if (points.length === 0) return;

            const t0 = points[0][0];
            const t1 = points[points.length - 1][0];
            const span = Math.max(1, t1 - t0);
            const x = (ts) => (points.length === 1 ? (pad.left + W - pad.right) / 2 : pad.left + ((ts - t0) / span) * (W - pad.left - pad.right));
            state.xs = points.map((p) => x(p[0]));

            const ticks = Math.min(6, points.length);
            for (let i = 0; i < ticks; i++) {
                const p = points[Math.round((i * (points.length - 1)) / Math.max(1, ticks - 1))];
                const tx = svg('text', { x: x(p[0]), y: H - 6, class: 'mon-axis', 'text-anchor': i === 0 ? 'start' : i === ticks - 1 ? 'end' : 'middle' });
                tx.textContent = formatAxisTime(p[0], bucket);
                grid.append(tx);
            }

            [a, b].forEach((s, i) => {
                const coords = points.map((p) => `${x(p[0]).toFixed(1)},${y(p[i + 1]).toFixed(1)}`);
                const base = H - pad.bottom;
                areas.append(svg('polygon', { points: `${x(t0).toFixed(1)},${base} ${coords.join(' ')} ${x(t1).toFixed(1)},${base}`, fill: `url(#${id}-${i})`, class: 'mon-area' }));
                lines.append(svg('polyline', { points: coords.join(' '), class: `mon-line ${s.className}`, fill: 'none' }));
            });
            state.y = y;
        }

        function hover(clientX) {
            const { points, xs } = state;
            if (points.length === 0) return;
            const rect = root.getBoundingClientRect();
            const vx = ((clientX - rect.left) / rect.width) * W;
            let best = 0;
            xs.forEach((px, i) => { if (Math.abs(px - vx) < Math.abs(xs[best] - vx)) best = i; });
            const p = points[best];
            hoverLine.setAttribute('x1', xs[best]); hoverLine.setAttribute('x2', xs[best]); hoverLine.setAttribute('visibility', 'visible');
            dots.forEach((d, i) => { d.setAttribute('cx', xs[best]); d.setAttribute('cy', state.y(p[i + 1])); d.setAttribute('visibility', 'visible'); });

            tip.replaceChildren(el('strong', null, state.bucket >= 86400 ? formatAxisTime(p[0], 86400) : formatClock(p[0])));
            opts.series.forEach((s, i) => {
                const row = el('div', 'mon-tip-row');
                row.append(el('span', `mon-swatch ${s.className}`), el('span', null, `${s.label}: ${opts.format(p[i + 1])}`));
                tip.append(row);
            });
            tip.hidden = false;
            const leftPct = (xs[best] / W) * 100;
            tip.classList.toggle('flip', leftPct > 60);
            tip.style.left = `${leftPct}%`;
        }

        const leave = () => { hoverLine.setAttribute('visibility', 'hidden'); dots.forEach((d) => d.setAttribute('visibility', 'hidden')); tip.hidden = true; };
        overlay.addEventListener('mousemove', (e) => hover(e.clientX));
        overlay.addEventListener('touchmove', (e) => { if (e.touches[0]) hover(e.touches[0].clientX); }, { passive: true });
        overlay.addEventListener('mouseleave', leave);
        overlay.addEventListener('touchend', leave);

        return { draw };
    }

    /** Pager bernomor dengan elipsis: ‹ 1 2 3 4 5 … 49 ›. onPage(n) dipanggil saat dipilih. */
    function renderPager(host, page, last, onPage) {
        host.replaceChildren();
        if (last <= 1) return;

        const add = (label, target, opts = {}) => {
            const b = el('button', 'mon-page', label);
            b.type = 'button';
            if (opts.aria) b.setAttribute('aria-label', opts.aria);
            if (opts.current) { b.classList.add('is-current'); b.setAttribute('aria-current', 'page'); }
            if (opts.disabled) b.disabled = true;
            else b.addEventListener('click', () => onPage(target));
            host.append(b);
        };

        add('‹', page - 1, { aria: 'Halaman sebelumnya', disabled: page <= 1 });
        const shown = new Set([1, last, page - 1, page, page + 1]);
        if (page <= 3) [2, 3, 4, 5].forEach((n) => shown.add(n));
        if (page >= last - 2) [last - 1, last - 2, last - 3, last - 4].forEach((n) => shown.add(n));
        let prev = 0;
        [...shown].filter((n) => n >= 1 && n <= last).sort((m, n) => m - n).forEach((n) => {
            if (prev && n - prev > 1) host.append(el('span', 'mon-ellipsis', '…'));
            add(String(n), n, { current: n === page, aria: `Halaman ${n}` });
            prev = n;
        });
        add('›', page + 1, { aria: 'Halaman berikutnya', disabled: page >= last });
    }

    /**
     * Pemuat data dengan auto-refresh. fetchFn() mengembalikan Promise data; render(data) menggambar.
     * Permintaan lama dibatalkan bila ada yang baru, dan polling berhenti saat tab tersembunyi.
     */
    function createLoader({ url, params, render, onState, minSecondsFor }) {
        let timer = 0;
        let controller = null;
        let seconds = 0;

        async function load() {
            controller?.abort();
            controller = new AbortController();
            onState?.('loading');
            try {
                const response = await fetch(`${url}?${params().toString()}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                render(await response.json());
                onState?.('live');
            } catch (error) {
                if (error.name === 'AbortError') return;
                onState?.('error');
            }
        }

        function schedule() {
            window.clearInterval(timer);
            if (seconds > 0) {
                timer = window.setInterval(() => { if (!document.hidden) load(); }, seconds * 1000);
            }
        }

        document.addEventListener('visibilitychange', () => { if (!document.hidden && seconds > 0) load(); });

        return {
            load,
            setSeconds(value) {
                const floor = minSecondsFor ? minSecondsFor() : 0;
                seconds = value > 0 ? Math.max(value, floor) : 0;
                schedule();
                return seconds;
            },
        };
    }

    function setLive(badge, state) {
        badge.classList.remove('is-live', 'is-error', 'is-paused');
        const map = { live: ['is-live', 'Live'], loading: [null, null], error: ['is-error', 'Gagal memuat'], paused: ['is-paused', 'Dijeda'] };
        const [cls, text] = map[state] || [];
        if (state === 'loading') return;
        if (cls) badge.classList.add(cls);
        badge.querySelector('.mon-live-text').textContent = text;
    }

    return { el, svg, formatRate, formatBytes, formatNumber, formatCompact, formatClock, formatDateTime, formatAxisTime, debounce, store, createAreaChart, renderPager, createLoader, setLive };
})();
