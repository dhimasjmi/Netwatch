@extends('layouts.app')

@section('title', 'Database & Backup')

@section('main-attrs')data-page="database"@endsection

@section('content')
    <h1>Database &amp; Backup</h1>
    <p class="muted">Ukuran data, kesegaran pembersihan retensi, dan status backup. Halaman ini hanya membaca; backup berjalan otomatis lewat scheduler (<code>php artisan netwatch:backup</code>).</p>

    @foreach ($report['warnings'] as $warning)
        <div class="alert alert-error" role="alert">{{ $warning }}</div>
    @endforeach
    @if ($report['warnings'] === [])
        <div class="alert alert-ok" role="status">Tidak ada masalah terdeteksi.</div>
    @endif

    <section class="cards">
        <div class="card"><div class="card-label">Ukuran database</div><div class="card-value">{{ number_format($report['size_bytes'] / 1073741824, 2, ',', '.') }} GB</div></div>
        <div class="card"><div class="card-label">Backup terakhir</div><div class="card-value dash-text">{{ $report['backup']['latest_at']?->timezone(config('netwatch.display_timezone'))->format('d M Y H:i') ?? 'Belum ada' }}</div><div class="muted">{{ $report['backup']['count'] }} berkas tersimpan</div></div>
        <div class="card"><div class="card-label">Ruang disk backup</div><div class="card-value">{{ $report['backup']['free_bytes'] !== null ? number_format($report['backup']['free_bytes'] / 1073741824, 1, ',', '.').' GB' : '—' }}</div><div class="muted">{{ $report['backup']['directory'] }}</div></div>
        <div class="card"><div class="card-label">Salinan kedua</div><div class="card-value dash-text">{{ $report['backup']['copy_to'] ?? 'Belum diatur' }}</div></div>
    </section>

    <h2>Tabel terbesar</h2>
    <table class="table">
        <thead><tr><th>Tabel</th><th>Ukuran</th><th>Perkiraan baris</th><th>Baris mati</th><th>Vacuum terakhir</th></tr></thead>
        <tbody>
        @foreach ($report['tables'] as $t)
            <tr><td>{{ $t['name'] }}</td><td>{{ number_format($t['total_bytes'] / 1048576, 1, ',', '.') }} MB</td><td>{{ number_format($t['rows'], 0, ',', '.') }}</td><td>{{ number_format($t['dead'], 0, ',', '.') }}</td><td class="muted">{{ $t['last_vacuum'] ?? '—' }}</td></tr>
        @endforeach
        </tbody>
    </table>

    <h2>Retensi data</h2>
    <table class="table">
        <thead><tr><th>Data</th><th>Retensi</th><th>Data tertua</th><th>Pembersihan</th></tr></thead>
        <tbody>
        @foreach ($report['series'] as $s)
            <tr>
                <td>{{ $s['label'] }}</td><td>{{ $s['retention_days'] }} hari</td>
                <td>{{ $s['oldest']?->timezone(config('netwatch.display_timezone'))->format('d M Y H:i') ?? '—' }}</td>
                <td><span class="badge {{ $s['behind'] ? 'badge-off' : 'badge-ok' }}">{{ $s['behind'] ? 'TERTINGGAL' : 'OK' }}</span></td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <h2>Backup terbaru</h2>
    <table class="table">
        <thead><tr><th>Berkas</th><th>Ukuran</th></tr></thead>
        <tbody>
        @forelse ($report['backup']['files'] as $f)
            <tr><td>{{ $f['name'] }}</td><td>{{ number_format($f['bytes'] / 1048576, 1, ',', '.') }} MB</td></tr>
        @empty
            <tr><td colspan="2" class="muted">Belum ada backup.</td></tr>
        @endforelse
        </tbody>
    </table>
    <p class="muted">Cara memulihkan ada di <code>docs/BACKUP-DATABASE.md</code>. Uji pemulihan secara berkala di komputer lain; backup yang belum pernah diuji belum bisa dianggap aman.</p>
@endsection
