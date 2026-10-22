@extends('layouts.app')

@section('title', 'Laporan penilaian tiket')

@section('main-attrs')data-page="ticket-report"@endsection

@section('content')
    <div class="page-head">
        <h1>Laporan penilaian tiket</h1>
        <div class="actions"><a class="btn" href="{{ route('tickets.index') }}">Kembali ke daftar</a></div>
    </div>

    <form method="GET" class="filters">
        <label>Dari <input type="date" name="from" value="{{ $range->inputFrom() }}" max="{{ $range->inputTo() }}"></label>
        <label>Sampai <input type="date" name="to" value="{{ $range->inputTo() }}"></label>
        <button type="submit" class="btn">Terapkan</button>
        @foreach (['7d' => '7 hari', '30d' => '30 hari'] as $key => $label)
            <button type="submit" name="preset" value="{{ $key }}" class="btn btn-small">{{ $label }}</button>
        @endforeach
    </form>
    <p class="muted">Rentang: {{ $range->label() }} (waktu {{ config('netwatch.display_timezone') }})</p>

    <section class="cards">
        <div class="card"><div class="card-label">Tiket dibuat</div><div class="card-value">{{ $report['created'] }}</div></div>
        <div class="card"><div class="card-label">Diselesaikan</div><div class="card-value">{{ $report['resolved'] }}</div></div>
        <div class="card"><div class="card-label">Rata-rata waktu penyelesaian</div><div class="card-value">{{ $report['avg_resolution_hours'] !== null ? $report['avg_resolution_hours'].' jam' : '—' }}</div></div>
        <div class="card"><div class="card-label">Belum selesai sekarang</div><div class="card-value">{{ $report['open_now'] }}</div></div>
        <div class="card"><div class="card-label">Nilai rata-rata ({{ $report['rated'] }} penilaian)</div><div class="card-value">{{ $report['average'] !== null ? $report['average'].' / 5' : '—' }}</div></div>
    </section>

    <h2>Sebaran nilai</h2>
    <table class="table">
        <thead><tr><th>Nilai</th><th>Jumlah</th></tr></thead>
        <tbody>
        @foreach (array_reverse($report['distribution'], true) as $rating => $count)
            <tr><td>{{ $rating }}</td><td>{{ $count }}</td></tr>
        @endforeach
        </tbody>
    </table>

    <h2>Per jenis masalah</h2>
    <table class="table">
        <thead><tr><th>Jenis</th><th>Tiket</th><th>Dinilai</th><th>Nilai rata-rata</th></tr></thead>
        <tbody>
        @forelse ($report['by_category'] as $row)
            <tr>
                <td>{{ $categories[$row->category] ?? $row->category }}</td>
                <td>{{ $row->tickets }}</td><td>{{ $row->rated }}</td>
                <td>{{ $row->avg_rating !== null ? round((float) $row->avg_rating, 2) : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">Belum ada tiket pada rentang ini.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Komentar nilai rendah (3 ke bawah)</h2>
    <table class="table">
        <thead><tr><th>Tiket</th><th>Nilai</th><th>Komentar</th><th>Waktu</th></tr></thead>
        <tbody>
        @forelse ($report['low_comments'] as $row)
            <tr>
                <td><a href="{{ route('tickets.show', $row->id) }}">T-{{ str_pad((string) $row->id, 4, '0', STR_PAD_LEFT) }}</a> {{ $row->subject }}</td>
                <td>{{ $row->rating }}</td>
                <td class="pre-line">{{ $row->comment }}</td>
                <td class="muted">{{ \Illuminate\Support\Carbon::parse($row->submitted_at)->toDisplay() }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">Tidak ada komentar bernilai rendah.</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
