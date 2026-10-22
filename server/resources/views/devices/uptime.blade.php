@extends('layouts.app')

@section('title', 'Uptime '.$device->displayLabel())

@section('content')
    @php($availability = $summary->availabilityPercent())
    @php($tone = $availability === null ? '' : ($availability >= 99 ? 'ok' : ($availability >= 95 ? 'warn' : 'bad')))

    <div class="page-head">
        <h1>Riwayat uptime: {{ $device->displayLabel() }}</h1>
        <div class="actions"><a class="btn" href="{{ route('devices.show', $device) }}">Kembali ke perangkat</a></div>
    </div>

    @include('partials.range-filter', ['range' => $range, 'action' => route('devices.uptime', $device)])
    <p class="muted">Rentang: {{ $range->label() }} (waktu {{ config('netwatch.display_timezone') }})</p>

    <section class="cards">
        <div class="card">
            <div class="card-label">Ketersediaan</div>
            <div class="card-value {{ $tone }}">{{ $availability === null ? '—' : number_format($availability, 2).'%' }}</div>
            <div class="muted">dari {{ \App\Support\Duration::human($summary->coveredSeconds) }} tercatat</div>
        </div>
        <div class="card">
            <div class="card-label">Total downtime</div>
            <div class="card-value">{{ \App\Support\Duration::human($summary->downSeconds) }}</div>
        </div>
        <div class="card">
            <div class="card-label">Jumlah gangguan</div>
            <div class="card-value">{{ $summary->outageCount }}</div>
            <div class="muted">Rata-rata (MTTR): {{ $summary->mttrSeconds() === null ? '—' : \App\Support\Duration::human($summary->mttrSeconds()) }}</div>
        </div>
        <div class="card">
            <div class="card-label">Gangguan terlama</div>
            <div class="card-value">{{ $summary->outageCount === 0 ? '—' : \App\Support\Duration::human($summary->longestOutageSeconds) }}</div>
        </div>
    </section>

    <section>
        <h2>Timeline</h2>
        @include('partials.timeline', ['segments' => $segments, 'range' => $range])
    </section>

    <section>
        <h2>Daftar gangguan</h2>
        <table class="table">
            <thead><tr><th>Mulai</th><th>Selesai</th><th>Durasi</th><th>Keterangan</th></tr></thead>
            <tbody>
            @forelse ($outages as $outage)
                <tr>
                    <td>{{ $outage->started_at->toDisplay() }}</td>
                    <td>{{ $outage->ended_at?->toDisplay() ?? 'Masih berlangsung' }}</td>
                    <td>{{ \App\Support\Duration::human(($outage->ended_at ?? now())->getTimestamp() - $outage->started_at->getTimestamp()) }}</td>
                    <td class="muted">{{ $outage->reasonLabel() }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Tidak ada gangguan pada rentang ini.</td></tr>
            @endforelse
            </tbody>
        </table>
        <p class="muted">Waktu mulai gangguan adalah heartbeat terakhir yang diterima; selisih sampai beberapa detik (satu interval heartbeat) wajar.</p>
    </section>
@endsection
