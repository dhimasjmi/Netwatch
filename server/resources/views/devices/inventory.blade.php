@extends('layouts.app')

@section('title', 'Port & layanan · '.$device->displayLabel())

@section('main-attrs')data-page="inventory"@endsection

@section('content')
    <div class="page-head">
        <h1>Port &amp; layanan — {{ $device->displayLabel() }}</h1>
        <div class="actions"><a class="btn" href="{{ route('devices.show', $device) }}">Kembali ke perangkat</a></div>
    </div>

    @if ($device->inventory_reported_at === null)
        <p class="muted">Belum ada data. Agent mengirim daftar port dan layanan tiap 10 menit (butuh agent 1.4.0 atau lebih baru; versi sekarang: {{ $device->agent_version ?? '—' }}).</p>
    @else
        <p class="muted">Diperbarui {{ $device->inventory_reported_at->toDisplay() }} (waktu {{ config('netwatch.display_timezone') }}). Port yang baru terbuka ke jaringan dicatat di Event Viewer.</p>
    @endif

    <nav class="tabs" aria-label="Jenis data">
        <a href="{{ route('devices.inventory', ['device' => $device, 'tab' => 'ports']) }}" @class(['active' => $tab === 'ports']) @if($tab === 'ports') aria-current="page" @endif>Port terbuka ({{ $portStats['total'] }})</a>
        <a href="{{ route('devices.inventory', ['device' => $device, 'tab' => 'services']) }}" @class(['active' => $tab === 'services']) @if($tab === 'services') aria-current="page" @endif>Layanan ({{ $serviceStats['total'] }})</a>
    </nav>

    <form method="GET" class="filters">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <input type="search" name="q" value="{{ $search }}" placeholder="{{ $tab === 'ports' ? 'Cari port, proses, atau nama…' : 'Cari nama layanan…' }}" maxlength="100" aria-label="Cari">
        <select name="filter" aria-label="Filter">
            <option value="">Semua</option>
            @if ($tab === 'ports')
                <option value="exposed" @selected($filter === 'exposed')>Terbuka ke jaringan</option>
                <option value="attention" @selected($filter === 'attention')>Perlu perhatian</option>
            @else
                <option value="running" @selected($filter === 'running')>Berjalan</option>
                <option value="stopped" @selected($filter === 'stopped')>Berhenti</option>
                <option value="auto_stopped" @selected($filter === 'auto_stopped')>Otomatis tapi berhenti</option>
            @endif
        </select>
        <button type="submit" class="btn">Terapkan</button>
    </form>

    @if ($tab === 'ports')
        <section class="cards">
            <div class="card"><div class="card-label">Port terdaftar</div><div class="card-value">{{ $portStats['total'] }}</div></div>
            <div class="card"><div class="card-label">Terbuka ke jaringan</div><div class="card-value">{{ $portStats['exposed'] }}</div></div>
            <div class="card"><div class="card-label">Perlu perhatian</div><div class="card-value {{ $portStats['attention'] > 0 ? 'bad' : '' }}">{{ $portStats['attention'] }}</div></div>
        </section>
        <p class="muted">"Hanya lokal" berarti port hanya menerima koneksi dari PC itu sendiri, jadi tidak terjangkau dari jaringan. Port UDP sementara (49152+) tidak ditampilkan.</p>
        <table class="table">
            <thead><tr><th>Protokol</th><th>Port</th><th>Layanan umum</th><th>Proses</th><th>Jangkauan</th><th>Pertama terlihat</th></tr></thead>
            <tbody>
            @forelse ($ports as $p)
                <tr>
                    <td>{{ strtoupper($p['protocol']) }}</td>
                    <td><strong>{{ $p['port'] }}</strong> @if ($p['is_new'])<span class="badge badge-pending">Baru</span>@endif</td>
                    <td>
                        {{ $p['name'] ?? '—' }}
                        @if ($p['level'] !== 'normal')
                            <span class="badge {{ $p['level'] === 'high' ? 'badge-failed' : 'badge-pending' }}">{{ $p['level'] === 'high' ? 'Berisiko' : 'Perhatian' }}</span>
                        @endif
                        @if ($p['hint'])<div class="muted">{{ $p['hint'] }}</div>@endif
                    </td>
                    <td>{{ $p['process'] ?? '—' }}</td>
                    <td>{{ $p['exposed'] ? 'Terbuka ke jaringan' : 'Hanya lokal' }}</td>
                    <td class="muted">{{ $p['first_seen_at']->toDisplay() }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Tidak ada port yang cocok.</td></tr>
            @endforelse
            </tbody>
        </table>
    @else
        <section class="cards">
            <div class="card"><div class="card-label">Layanan</div><div class="card-value">{{ $serviceStats['total'] }}</div></div>
            <div class="card"><div class="card-label">Berjalan</div><div class="card-value ok">{{ $serviceStats['running'] }}</div></div>
            <div class="card"><div class="card-label">Otomatis tapi berhenti</div><div class="card-value">{{ $serviceStats['auto_stopped'] }}</div></div>
        </section>
        <p class="muted">Sebagian layanan bermode "Auto" memang berhenti sendiri setelah selesai bekerja (mis. pembaruan), jadi angka ini hanya petunjuk awal.</p>
        <table class="table">
            <thead><tr><th>Layanan</th><th>Nama internal</th><th>Status</th><th>Mulai</th></tr></thead>
            <tbody>
            @forelse ($services as $s)
                <tr>
                    <td>{{ $s->display_name ?? $s->name }}</td>
                    <td><code>{{ $s->name }}</code></td>
                    <td><span class="badge {{ strtolower($s->state) === 'running' ? 'badge-ok' : '' }}">{{ $s->state }}</span></td>
                    <td class="muted">{{ $s->start_mode ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Tidak ada layanan yang cocok.</td></tr>
            @endforelse
            </tbody>
        </table>
    @endif
@endsection
