    <div class="mon-head">
        <div>
            <h1>{{ $device ? 'Browsing · '.$device->displayLabel() : 'Browsing Monitoring' }}</h1>
            <p class="muted">{{ $device ? 'Riwayat akses web perangkat ini. Data yang sama dengan Browsing Monitoring, difilter ke satu perangkat.' : 'Aktivitas akses web dari semua perangkat.' }} Data sensitif: hanya administrator, setiap akses tercatat di Audit Log.</p>
        </div>
        <div class="mon-controls">
            <span class="mon-live" id="mon-live"><span class="mon-live-dot"></span><span class="mon-live-text">Memuat…</span></span>
            <label>Auto refresh
                <select id="mon-refresh">
                    <option value="0">Mati</option>
                    <option value="5">5 detik</option>
                    <option value="10">10 detik</option>
                    <option value="30" selected>30 detik</option>
                    <option value="60">1 menit</option>
                </select>
            </label>
            <label>Rentang
                <select id="mon-window">
                    @foreach (['1h' => '1 jam', '6h' => '6 jam', '24h' => '24 jam', '7d' => '7 hari', '30d' => '30 hari'] as $key => $label)
                        <option value="{{ $key }}" @selected($key === '1h')>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </div>

    @unless (config('netwatch.browsing.enabled'))
        <div class="alert alert-error" role="alert">
            Pengumpulan riwayat browsing belum aktif. Setelah sesuai ketentuan perusahaan, set <code>NETWATCH_BROWSING_ENABLED=true</code> di .env lalu <code>php artisan config:clear</code>. Perlu agent 1.3.0 di komputer klien.
        </div>
    @endunless

    <div class="mon-kpis">
        <div class="mon-kpi"><span class="mon-kpi-icon kpi-a" aria-hidden="true">●</span><div><div class="mon-kpi-label">{{ $device ? 'Aktivitas perangkat' : 'Perangkat melapor' }}</div><div class="mon-kpi-value" id="kpi-active">—</div><div class="mon-kpi-sub" id="kpi-active-sub"></div></div></div>
        <div class="mon-kpi"><span class="mon-kpi-icon kpi-rx" aria-hidden="true">◎</span><div><div class="mon-kpi-label">Total aktivitas</div><div class="mon-kpi-value" id="kpi-total">—</div><div class="mon-kpi-sub">situs + koneksi</div></div></div>
        <div class="mon-kpi"><span class="mon-kpi-icon kpi-tx" aria-hidden="true">#</span><div><div class="mon-kpi-label">Situs unik</div><div class="mon-kpi-value" id="kpi-sites">—</div><div class="mon-kpi-sub">domain berbeda</div></div></div>
        <div class="mon-kpi"><span class="mon-kpi-icon kpi-total" aria-hidden="true">★</span><div><div class="mon-kpi-label">Situs teratas</div><div class="mon-kpi-value mon-kpi-text" id="kpi-top">—</div><div class="mon-kpi-sub">paling sering diakses</div></div></div>
    </div>

    <div class="mon-grid-2">
        <section class="mon-panel">
            <div class="mon-panel-head">
                <h2>Tren Akses Web</h2>
                <div class="mon-legend"><span><i class="mon-swatch series-rx"></i> Situs</span><span><i class="mon-swatch series-tx"></i> Koneksi</span></div>
            </div>
            <div id="mon-chart"></div>
            <p class="muted" id="chart-note"></p>
        </section>
        <section class="mon-panel">
            <div class="mon-panel-head"><h2>Situs Teratas</h2><a href="{{ route('browsing.index') }}">Laporan lengkap →</a></div>
            <ol class="mon-top" id="mon-top"><li class="muted">Memuat…</li></ol>
        </section>
    </div>

    <section class="mon-panel">
        <div class="mon-panel-head">
            <h2>Aktivitas Browsing Terbaru</h2>
            <div class="mon-toolbar">
                <input type="search" id="mon-search" placeholder="Cari domain atau IP…" maxlength="100" aria-label="Cari domain atau IP">
                @unless ($device)
                    <select id="mon-device" aria-label="Perangkat">
                    <option value="">Semua perangkat</option>
                    @foreach ($devices as $d)
                        <option value="{{ $d->public_id }}">{{ $d->displayLabel() }}</option>
                    @endforeach
                </select>
                @endunless
                <select id="mon-kind" aria-label="Jenis">
                    <option value="">Situs + koneksi</option>
                    <option value="url">Situs</option>
                    <option value="connection">Koneksi (IP)</option>
                </select>
                <label>Tampilkan
                    <select id="mon-perpage">
                        @foreach ($perPage as $n)
                            <option value="{{ $n }}" @selected($n === 10)>{{ $n }}</option>
                        @endforeach
                    </select>
                </label>
                <a class="btn btn-small" href="{{ route('browsing.csv', $device ? ['device' => $device->public_id] : []) }}">Ekspor CSV</a>
            </div>
        </div>
        <div class="mon-table-wrap">
            <table class="table mon-table">
                <thead><tr><th>No</th><th>Waktu</th><th>Perangkat</th><th>IP perangkat</th><th>Situs / tujuan</th><th>Jenis</th><th>Aplikasi</th><th>IP tujuan</th></tr></thead>
                <tbody id="mon-rows"><tr><td colspan="8" class="muted">Memuat…</td></tr></tbody>
            </table>
        </div>
        <div class="mon-foot">
            <span class="muted" id="mon-showing"></span>
            <div class="mon-pager" id="mon-pager"></div>
        </div>
        <p class="muted kiosk-hide" id="mon-hint">Kategori situs, status blokir, dan durasi sesi tidak dikumpulkan agent, sehingga tidak ditampilkan. "Koneksi" berarti komputer terhubung ke IP itu, bukan jumlah halaman dibuka.</p>
    </section>
