@extends('layouts.app')

@section('title', 'Inventaris')

@section('main-attrs')data-page="assets"@endsection

@section('content')
    <div class="page-head"><h1>Inventaris perangkat</h1></div>

    <nav class="tabs" aria-label="Jenis inventaris">
        <a href="{{ route('assets.index', ['tab' => 'hardware']) }}" @class(['active' => $tab === 'hardware']) @if($tab === 'hardware') aria-current="page" @endif>Perangkat keras</a>
        <a href="{{ route('assets.index', ['tab' => 'software']) }}" @class(['active' => $tab === 'software']) @if($tab === 'software') aria-current="page" @endif>Perangkat lunak</a>
    </nav>

    <form method="GET" class="filters">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <input type="search" name="q" value="{{ $search }}" maxlength="100" aria-label="Cari"
               placeholder="{{ $tab === 'hardware' ? 'Cari perangkat, model, CPU, atau nomor seri…' : 'Cari nama program (mis. Chrome, TeamViewer)…' }}">
        <button type="submit" class="btn">Cari</button>
    </form>

    @if ($tab === 'hardware')
        <table class="table">
            <thead><tr><th>Perangkat</th><th>Produsen / model</th><th>Prosesor</th><th>RAM</th><th>Disk</th><th>OS</th><th>Nomor seri</th></tr></thead>
            <tbody>
            @forelse ($hardware as $d)
                @php($h = $d->hardware)
                <tr>
                    <td><a href="{{ route('devices.assets', $d) }}">{{ $d->displayLabel() }}</a></td>
                    @if ($h === null)
                        <td colspan="6" class="muted">Belum dilaporkan (perlu agent 1.6.0+).</td>
                    @else
                        <td>{{ trim(($h->manufacturer ?? '').' '.($h->model ?? '')) ?: '—' }}</td>
                        <td>{{ $h->cpu ?? '—' }}</td>
                        <td>{{ $h->memoryLabel() }}</td>
                        <td>{{ $h->disksLabel() }}</td>
                        <td>{{ $h->os ?? '—' }}</td>
                        <td class="muted">{{ $h->serial ?? '—' }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Tidak ada perangkat yang cocok.</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $hardware->links('pagination.pages') }}
    @else
        <p class="muted">Diurutkan dari yang paling banyak terpasang. Klik nama program untuk melihat perangkat mana saja yang memasangnya.</p>
        <table class="table">
            <thead><tr><th>Program</th><th>Jumlah perangkat</th><th>Versi</th></tr></thead>
            <tbody>
            @forelse ($software as $s)
                <tr>
                    <td><a href="{{ route('assets.software', ['name' => $s->name]) }}">{{ $s->name }}</a></td>
                    <td>{{ $s->devices }}</td>
                    <td class="muted">{{ \Illuminate\Support\Str::limit((string) $s->versions, 80) ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted">Belum ada data program terpasang.</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $software->links('pagination.pages') }}
    @endif
@endsection
