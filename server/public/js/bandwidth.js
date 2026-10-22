'use strict';

/**
 * Grafik tren bandwidth per interface (SVG buatan sendiri, tanpa library).
 * Semua teks dari server dimasukkan lewat textContent agar aman dari XSS.
 */
(() => {
    const main = document.querySelector('main[data-page="bandwidth"]');
    if (!main) return;

    const SVG_NS = 'http://www.w3.org/2000/svg';
    const WIDTH = 900;
    const HEIGHT = 220;
    const PAD = { left: 64, right: 12, top: 10, bottom: 24 };
    const container = document.getElementById('bw-container');
    const emptyNote = document.getElementById('bw-empty');
    const meta = document.getElementById('bw-meta');
    const refreshMs = Math.max(10, Number(main.dataset.refreshSeconds) || 30) * 1000;

    function formatRate(bps) {
        const units = ['bps', 'Kbps', 'Mbps', 'Gbps'];
        let value = bps;
        let i = 0;
        while (value >= 1000 && i < units.length - 1) { value /= 1000; i++; }
        return `${value >= 100 || i === 0 ? Math.round(value) : value.toFixed(1)} ${units[i]}`;
    }

    function formatBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let value = bytes;
        let i = 0;
        while (value >= 1024 && i < units.length - 1) { value /= 1024; i++; }
        return `${value >= 100 || i === 0 ? Math.round(value) : value.toFixed(1)} ${units[i]}`;
    }

    function formatTime(ts, bucket) {
        const d = new Date(ts * 1000);
        const opts = bucket >= 86400
            ? { day: '2-digit', month: 'short' }
            : { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' };
        return d.toLocaleString('id-ID', { ...opts, timeZone: main.dataset.timezone || undefined });
    }

    function el(name, attrs = {}, text = null) {
        const node = document.createElementNS(SVG_NS, name);
        for (const [k, v] of Object.entries(attrs)) node.setAttribute(k, String(v));
        if (text !== null) node.textContent = text;
        return node;
    }

    /** Pecah deret titik menjadi segmen terpisah bila ada celah (PC mati/offline), agar garis tidak menipu. */
    function segments(points, valueIndex, bucket) {
        const result = [];
        let current = [];
        let previousTs = null;
        for (const point of points) {
            if (previousTs !== null && point[0] - previousTs > bucket * 1.5) {
                if (current.length) result.push(current);
                current = [];
            }
            current.push([point[0], point[valueIndex]]);
            previousTs = point[0];
        }
        if (current.length) result.push(current);
        return result;
    }

    function drawChart(iface, from, to, bucket) {
        const svg = el('svg', { viewBox: `0 0 ${WIDTH} ${HEIGHT}`, class: 'bw-chart', role: 'img', 'aria-label': `Grafik bandwidth ${iface.name}` });
        const plotW = WIDTH - PAD.left - PAD.right;
        const plotH = HEIGHT - PAD.top - PAD.bottom;
        const maxValue = Math.max(1, ...iface.points.map((p) => Math.max(p[1], p[2]))) * 1.1;
        const x = (ts) => PAD.left + ((ts - from) / Math.max(1, to - from)) * plotW;
        const y = (v) => PAD.top + plotH - (v / maxValue) * plotH;

        for (let i = 0; i <= 4; i++) {
            const value = (maxValue / 4) * i;
            svg.append(el('line', { x1: PAD.left, x2: WIDTH - PAD.right, y1: y(value), y2: y(value), class: 'bw-grid' }));
            svg.append(el('text', { x: PAD.left - 6, y: y(value) + 4, class: 'bw-axis', 'text-anchor': 'end' }, formatRate(value)));
        }
        for (let i = 0; i <= 4; i++) {
            const ts = from + ((to - from) / 4) * i;
            const anchor = i === 0 ? 'start' : (i === 4 ? 'end' : 'middle');
            svg.append(el('text', { x: x(ts), y: HEIGHT - 6, class: 'bw-axis', 'text-anchor': anchor }, formatTime(ts, bucket)));
        }

        for (const [index, cls] of [[1, 'bw-rx'], [2, 'bw-tx']]) {
            for (const segment of segments(iface.points, index, bucket)) {
                if (segment.length === 1) {
                    svg.append(el('circle', { cx: x(segment[0][0]), cy: y(segment[0][1]), r: 2.5, class: `${cls}-dot` }));
                    continue;
                }
                const d = segment.map((p, i) => `${i === 0 ? 'M' : 'L'}${x(p[0]).toFixed(1)},${y(p[1]).toFixed(1)}`).join(' ');
                svg.append(el('path', { d, class: `bw-line ${cls}` }));
            }
        }

        return svg;
    }

    function stat(label, value) {
        const wrap = document.createElement('div');
        wrap.className = 'card';
        const l = document.createElement('div');
        l.className = 'card-label';
        l.textContent = label;
        const v = document.createElement('div');
        v.className = 'card-value bw-stat';
        v.textContent = value;
        wrap.append(l, v);
        return wrap;
    }

    function renderInterface(iface, data) {
        const section = document.createElement('section');
        section.className = 'bw-section';

        const title = document.createElement('h2');
        title.textContent = iface.name + (iface.speed_bps ? ` · ${formatRate(iface.speed_bps)}` : '');
        section.append(title);

        const stats = document.createElement('div');
        stats.className = 'cards';
        stats.append(
            stat('Unduh (rata-rata / puncak)', `${formatRate(iface.avg_rx_bps)} / ${formatRate(iface.peak_rx_bps)}`),
            stat('Unggah (rata-rata / puncak)', `${formatRate(iface.avg_tx_bps)} / ${formatRate(iface.peak_tx_bps)}`),
            stat('Total unduh', formatBytes(iface.total_rx_bytes)),
            stat('Total unggah', formatBytes(iface.total_tx_bytes)),
        );
        section.append(stats);

        if (iface.points.length === 0) {
            const note = document.createElement('p');
            note.className = 'muted';
            note.textContent = 'Tidak ada sampel pada rentang ini.';
            section.append(note);
            return section;
        }

        const legend = document.createElement('p');
        legend.className = 'muted bw-legend';
        legend.innerHTML = '<span class="bw-key bw-rx-key"></span> Unduh (masuk) <span class="bw-key bw-tx-key"></span> Unggah (keluar)'; // konstanta, bukan data server
        section.append(legend);
        section.append(drawChart(iface, data.from, data.to, data.bucket_seconds));

        return section;
    }

    async function load() {
        try {
            const response = await fetch(main.dataset.dataUrl, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();

            container.replaceChildren(...data.interfaces.map((iface) => renderInterface(iface, data)));
            emptyNote.hidden = data.interfaces.length > 0;
            const bucketLabel = data.bucket_seconds >= 3600 ? `${data.bucket_seconds / 3600} jam` : `${data.bucket_seconds / 60} menit`;
            meta.textContent = `resolusi ${bucketLabel} per titik · diperbarui ${new Date().toLocaleTimeString('id-ID')}`;
        } catch (error) {
            meta.textContent = 'gagal memuat data, mencoba lagi…';
        }
    }

    load();
    window.setInterval(() => { if (!document.hidden) load(); }, refreshMs);
})();
