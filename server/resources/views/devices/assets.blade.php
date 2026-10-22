@extends('layouts.app')

@section('title', 'Inventaris · '.$device->displayLabel())

@section('main-attrs')data-page="assets"@endsection

@section('content')
    <div class="page-head">
        <h1>Inventaris — {{ $device->displayLabel() }}</h1>
        <div class="actions"><a class="btn" href="{{ route('devices.show', $device) }}">Kembali ke perangkat</a></div>
    </div>

    @if ($device->assets_reported_at === null)
        <p class="muted">Belum ada data. Agent mengirim inventaris perangkat keras dan program terpasang (butuh agent 1.6.0 atau lebih baru; versi sekarang: {{ $device->agent_version ?? '—' }}).</p>
    @else
        <p class="muted">Dilaporkan {{ $device->assets_reported_at->toDisplay() }} (waktu {{ config('netwatch.display_timezone') }}). Agent memeriksa tiap 5 menit dan mengirim bila ada perubahan; program yang dihapus hilang dari daftar dan dicatat di Event Viewer.</p>
    @endif

    <section class="card">
        <h2>Perangkat keras</h2>
        @if ($hardware === null)
            <p class="muted">Belum ada data perangkat keras.</p>
        @else
            <dl class="details">
                <dt>Produsen / model</dt><dd>{{ trim(($hardware->manufacturer ?? '').' '.($hardware->model ?? '')) ?: '—' }}</dd>
                <dt>Nomor seri</dt><dd>{{ $hardware->serial ?? '—' }}</dd>
                <dt>Prosesor</dt><dd>{{ $hardware->cpu ?? '—' }}@if ($hardware->cpu_cores) · {{ $hardware->cpu_cores }} core @endif @if ($hardware->cpu_threads) / {{ $hardware->cpu_threads }} thread @endif</dd>
                <dt>Memori (RAM)</dt><dd>{{ $hardware->memoryLabel() }}</dd>
                <dt>Penyimpanan</dt><dd>
                    @forelse ($hardware->disks ?? [] as $disk){{ $disk['model'] }} ({{ $disk['size_gb'] }} GB)@if (! $loop->last)<br>@endif @empty — @endforelse
                </dd>
                <dt>Sistem operasi</dt><dd>{{ $hardware->os ?? '—' }} {{ $hardware->os_version }} @if ($hardware->os_build) (build {{ $hardware->os_build }}) @endif</dd>
            </dl>
        @endif
    </section>

    <h2>Program terpasang ({{ $softwareTotal }})</h2>
    <form method="GET" class="filters">
        <input type="search" name="q" value="{{ $search }}" placeholder="Cari nama program atau penerbit…" maxlength="100" aria-label="Cari program">
        <button type="submit" class="btn">Cari</button>
    </form>
    <table class="table">
        <thead><tr><th>Program</th><th>Versi</th><th>Penerbit</th><th>Tanggal pasang</th><th>Pertama terlihat</th></tr></thead>
        <tbody>
        @forelse ($software as $s)
            <tr>
                <td>{{ $s->name }}</td>
                <td>{{ $s->version !== '' ? $s->version : '—' }}</td>
                <td class="muted">{{ $s->publisher ?? '—' }}</td>
                <td class="muted">{{ $s->installed_on?->format('d M Y') ?? '—' }}</td>
                <td class="muted">{{ $s->first_seen_at->toDisplay() }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">Tidak ada program yang cocok.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $software->links('pagination.pages') }}
@endsection
