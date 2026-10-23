@extends('layouts.app')

@section('title', 'Bandwidth Monitoring')

@section('main-attrs')data-page="monitor-bandwidth" data-url="{{ route('monitor.bandwidth.data') }}"@endsection

@section('content')
    @include('monitoring.tabs', ['active' => 'bandwidth'])

    <div class="mon-head">
        <div>
            <h1>Bandwidth Monitoring</h1>
            <p class="muted">Penggunaan bandwidth seluruh perangkat, atau hanya yang dipilih.</p>
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
                    @foreach (['1h' => '1 jam', '6h' => '6 jam', '24h' => '24 jam', '7d' => '7 hari'] as $key => $label)
                        <option value="{{ $key }}" @selected($key === '1h')>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>Grup
                <select id="mon-group">
                    <option value="">Semua grup</option>
                    @foreach ($groups as $group)
                        <option value="{{ $group->id }}">{{ $group->name }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </div>

    <div class="mon-kpis">
        <div class="mon-kpi"><span class="mon-kpi-icon kpi-a" aria-hidden="true">●</span><div><div class="mon-kpi-label">Perangkat online</div><div class="mon-kpi-value" id="kpi-devices">—</div><div class="mon-kpi-sub" id="kpi-devices-sub"></div></div></div>
        <div class="mon-kpi"><span class="mon-kpi-icon kpi-rx" aria-hidden="true">↓</span><div><div class="mon-kpi-label">Download sekarang</div><div class="mon-kpi-value" id="kpi-rx">—</div><div class="mon-kpi-sub">gabungan perangkat</div></div></div>
        <div class="mon-kpi"><span class="mon-kpi-icon kpi-tx" aria-hidden="true">↑</span><div><div class="mon-kpi-label">Upload sekarang</div><div class="mon-kpi-value" id="kpi-tx">—</div><div class="mon-kpi-sub">gabungan perangkat</div></div></div>
        <div class="mon-kpi"><span class="mon-kpi-icon kpi-total" aria-hidden="true">Σ</span><div><div class="mon-kpi-label">Total transfer</div><div class="mon-kpi-value" id="kpi-total">—</div><div class="mon-kpi-sub" id="kpi-total-sub"></div></div></div>
    </div>

    <section class="mon-panel">
        <div class="mon-panel-head">
            <h2 id="chart-title">Tren Bandwidth</h2>
            <div class="mon-legend"><span><i class="mon-swatch series-rx"></i> Download</span><span><i class="mon-swatch series-tx"></i> Upload</span></div>
        </div>
        <div id="mon-chart"></div>
        <p class="muted" id="chart-note"></p>
    </section>

    <section class="mon-panel">
        <div class="mon-panel-head">
            <h2>Bandwidth per Perangkat</h2>
            <div class="mon-toolbar">
                <input type="search" id="mon-search" placeholder="Cari hostname atau IP…" maxlength="100" aria-label="Cari perangkat">
                <label class="mon-check"><input type="checkbox" id="mon-only"> Hanya yang dipilih</label>
                <button type="button" class="btn btn-small" id="mon-clear" hidden>Hapus pilihan (<span id="mon-selected">0</span>)</button>
                <label>Tampilkan
                    <select id="mon-perpage">
                        @foreach ($perPage as $n)
                            <option value="{{ $n }}" @selected($n === 10)>{{ $n }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>
        <div class="mon-table-wrap">
            <table class="table mon-table">
                <thead>
                <tr>
                    <th class="col-check"><input type="checkbox" id="mon-page-check" aria-label="Pilih semua di halaman ini"></th>
                    <th>No</th>
                    <th><button type="button" class="mon-sort" data-sort="name">Perangkat</button></th>
                    <th><button type="button" class="mon-sort" data-sort="ip">IP</button></th>
                    <th><button type="button" class="mon-sort" data-sort="rx">Download</button></th>
                    <th><button type="button" class="mon-sort" data-sort="tx">Upload</button></th>
                    <th><button type="button" class="mon-sort" data-sort="total">Total</button></th>
                    <th>Porsi</th>
                    <th>Status</th>
                    <th><button type="button" class="mon-sort" data-sort="last_seen">Terakhir aktif</button></th>
                </tr>
                </thead>
                <tbody id="mon-rows"><tr><td colspan="10" class="muted">Memuat…</td></tr></tbody>
            </table>
        </div>
        <div class="mon-foot">
            <span class="muted" id="mon-showing"></span>
            <div class="mon-pager" id="mon-pager"></div>
        </div>
        <p class="muted kiosk-hide">Download/Upload = laju rata-rata pada rentang terpilih. Porsi = dibanding perangkat terbesar. Centang beberapa perangkat untuk melihat tren gabungan hanya untuk mereka.</p>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/monitor-ui.js') }}" defer></script>
    <script src="{{ asset('js/monitor-bandwidth.js') }}" defer></script>
@endpush
