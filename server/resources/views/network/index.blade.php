@extends('layouts.app')

@section('title', 'Network Operations Center')

@section('main-attrs')data-page="network" data-autoreload data-refresh-seconds="30"@endsection

@section('content')
    <div class="page-head">
        <div>
            <h1>Network Operations Center</h1>
            <p class="muted noc-sub">Pemantauan perangkat jaringan secara real-time (di-ping dari server tiap menit)</p>
        </div>
        <span class="badge badge-ok">LIVE</span>
    </div>
    <p id="refresh-note" class="muted" hidden></p>

    @unless ($monitorEnabled)
        <div class="alert alert-error" role="alert">Pemantauan dimatikan (NETWATCH_MONITOR_ENABLED=false). Status di bawah tidak diperbarui.</div>
    @endunless

    <section class="cards">
        <div class="card"><div class="card-label">Total perangkat</div><div class="card-value">{{ $total }}</div><div class="muted">Semua target pantau</div></div>
        <div class="card"><div class="card-label">Online</div><div class="card-value ok">{{ $online + $slow }}</div><div class="ok">{{ $fleetAvailability !== null ? $fleetAvailability.'% tersedia' : 'Belum ada data' }}</div></div>
        <div class="card"><div class="card-label">Offline</div><div class="card-value {{ $offline > 0 ? 'bad' : '' }}">{{ $offline }}</div><div class="{{ $offline > 0 ? 'bad' : 'muted' }}">{{ $offline > 0 ? 'Perlu diperiksa' : 'Semua merespons' }}</div></div>
        <div class="card"><div class="card-label">Respons lambat</div><div class="card-value {{ $slow > 0 ? 'warn' : '' }}">{{ $slow }}</div><div class="{{ $slow > 0 ? 'warn' : 'muted' }}">Lebih dari {{ config('netwatch.monitor.warn_latency_ms') }} ms</div></div>
    </section>

    <form method="GET" action="{{ route('network.index') }}" class="filters">
        <select name="status" aria-label="Status">
            <option value="all">Semua status</option>
            @foreach (\App\Enums\TargetStatus::cases() as $s)<option value="{{ $s->value }}" @selected($filter === $s->value)>{{ $s->label() }}</option>@endforeach
        </select>
        <input type="search" name="q" value="{{ $search }}" placeholder="Cari nama atau IP" aria-label="Cari">
        <button type="submit" class="btn">Terapkan</button>
    </form>

    <h2>Status perangkat</h2>
    @if ($targets->isEmpty())
        <p class="muted">Belum ada target{{ $filter !== 'all' || $search !== '' ? ' yang cocok dengan filter' : '. Tambahkan IP switch, access point, printer, atau server di bawah' }}.</p>
    @else
        <div class="noc-grid">
            @foreach ($targets as $target)
                @php($targetBars = $bars[$target->id] ?? [])
                <article class="noc-card noc-{{ $target->is_enabled ? $target->status->value : 'paused' }}">
                    <header class="noc-card-head">
                        <span class="status-dot status-{{ $target->status->value }}" aria-hidden="true"></span>
                        <strong class="noc-name">{{ $target->name }}</strong>
                        @if ($target->is_enabled)
                            <span class="badge {{ $target->status->badgeClass() }}">{{ strtoupper($target->status->label()) }}</span>
                        @else
                            <span class="badge">DIHENTIKAN</span>
                        @endif
                    </header>

                    <div class="noc-ip">{{ $target->address }}</div>
                    <div class="muted">{{ $target->kind->label() }} · {{ $target->methodLabel() }}</div>

                    <div class="noc-latency {{ $target->status === \App\Enums\TargetStatus::Offline ? 'bad' : ($target->status === \App\Enums\TargetStatus::Warning ? 'warn' : 'ok') }}">
                        @if ($target->status->isUp() && $target->latency_ms !== null){{ round($target->latency_ms, 1) }}<small> ms</small>
                        @elseif ($target->status === \App\Enums\TargetStatus::Offline)Timeout
                        @else — @endif
                    </div>

                    @if ($targetBars !== [])
                        <svg class="noc-bars" viewBox="0 0 {{ \App\Support\LatencyBars::width(count($targetBars)) }} {{ \App\Support\LatencyBars::HEIGHT }}" role="img" aria-label="Riwayat {{ count($targetBars) }} pengecekan terakhir">
                            @foreach ($targetBars as $bar)<rect class="{{ $bar['class'] }}" x="{{ $bar['x'] }}" y="{{ $bar['y'] }}" width="{{ \App\Support\LatencyBars::BAR_WIDTH }}" height="{{ $bar['height'] }}"/>@endforeach
                        </svg>
                    @else
                        <div class="noc-bars-empty muted">Belum ada riwayat</div>
                    @endif

                    <footer class="muted noc-foot">
                        <span>@if ($target->last_checked_at)dicek {{ $target->last_checked_at->diffForHumans() }}@else belum dicek @endif</span>
                        @if (isset($availability24h[$target->id]))<span>24 jam: {{ $availability24h[$target->id] }}%</span>@endif
                    </footer>
                    @if ($target->notes)<div class="muted noc-notes">{{ $target->notes }}</div>@endif

                    @can('manage-fleet')
                        <div class="noc-actions">
                            <form method="POST" action="{{ route('network.ping', $target) }}">@csrf<button type="submit" class="btn btn-small">Ping sekarang</button></form>
                            <form method="POST" action="{{ route('network.toggle', $target) }}">@csrf<button type="submit" class="btn btn-small">{{ $target->is_enabled ? 'Hentikan' : 'Aktifkan' }}</button></form>
                        </div>
                        <details class="noc-edit">
                            <summary>Ubah / hapus</summary>
                            <form method="POST" action="{{ route('network.update', $target) }}" class="stack">
                                @csrf @method('PATCH')
                                @include('network.partials.fields', ['target' => $target, 'kinds' => $kinds])
                                <button type="submit" class="btn">Simpan</button>
                            </form>
                            <form method="POST" action="{{ route('network.destroy', $target) }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-small btn-danger">Hapus target</button>
                            </form>
                        </details>
                    @endcan
                </article>
            @endforeach
        </div>
    @endif

    @can('manage-fleet')
        <h2>Tambah target</h2>
        <p class="muted">Hanya alamat IP internal. Pilih "Port TCP" untuk perangkat yang memblokir ping (mis. web admin printer di port 80/443).</p>
        <form method="POST" action="{{ route('network.store') }}" class="filters">
            @csrf
            @include('network.partials.fields', ['target' => null, 'kinds' => $kinds])
            <button type="submit" class="btn btn-primary">Tambah</button>
        </form>
    @endcan
@endsection
