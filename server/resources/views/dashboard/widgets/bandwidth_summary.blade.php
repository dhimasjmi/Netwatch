@if ($d === null)
    <div class="card"><div class="card-label">Bandwidth</div><div class="muted">Data belum bisa dimuat.</div></div>
@else
    <a class="card card-link" href="{{ route('monitor.bandwidth') }}"><div class="card-label">Download sekarang</div><div class="card-value">{{ $d['kpi']['rx_bps_now'] >= 1_000_000 ? round($d['kpi']['rx_bps_now'] / 1_000_000, 1).' Mbps' : round($d['kpi']['rx_bps_now'] / 1000).' Kbps' }}</div><div class="muted">gabungan perangkat</div></a>
    <a class="card card-link" href="{{ route('monitor.bandwidth') }}"><div class="card-label">Upload sekarang</div><div class="card-value">{{ $d['kpi']['tx_bps_now'] >= 1_000_000 ? round($d['kpi']['tx_bps_now'] / 1_000_000, 1).' Mbps' : round($d['kpi']['tx_bps_now'] / 1000).' Kbps' }}</div><div class="muted">gabungan perangkat</div></a>
    <a class="card card-link dash-card-spark" href="{{ route('monitor.bandwidth') }}">
        <div class="card-label">Tren 1 jam</div>
        @if ($d['spark_rx'] !== '')
            <svg class="dash-spark" viewBox="0 0 200 40" preserveAspectRatio="none" role="img" aria-label="Tren download dan upload 1 jam terakhir">
                <polyline class="dash-spark-rx" points="{{ $d['spark_rx'] }}" fill="none"/>
                <polyline class="dash-spark-tx" points="{{ $d['spark_tx'] }}" fill="none"/>
            </svg>
            <div class="muted"><span class="dash-key-rx">●</span> Download <span class="dash-key-tx">●</span> Upload</div>
        @else
            <div class="muted">Belum ada data.</div>
        @endif
    </a>
@endif
