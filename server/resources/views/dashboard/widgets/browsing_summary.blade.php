@if ($d === null)
    <div class="card"><div class="card-label">Browsing</div><div class="muted">Data belum bisa dimuat.</div></div>
@else
    <a class="card card-link" href="{{ route('monitor.browsing') }}"><div class="card-label">Aktivitas web (1 jam)</div><div class="card-value">{{ number_format($d['kpi']['total'], 0, ',', '.') }}</div><div class="muted">{{ $d['kpi']['devices_active'] }} perangkat</div></a>
    <a class="card card-link" href="{{ route('monitor.browsing') }}"><div class="card-label">Situs unik</div><div class="card-value">{{ number_format($d['kpi']['unique_sites'], 0, ',', '.') }}</div></a>
    <a class="card card-link" href="{{ route('monitor.browsing') }}"><div class="card-label">Situs teratas</div><div class="card-value dash-text">{{ $d['kpi']['top_site'] ?? '—' }}</div></a>
@endif
