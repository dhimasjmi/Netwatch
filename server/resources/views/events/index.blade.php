@extends('layouts.app')

@section('title', 'Event')

@section('main-attrs')data-page="events" data-autoreload data-refresh-seconds="30"@endsection

@section('content')
    <h1>Event</h1>

    <form method="GET" class="filters">
        <select name="category" aria-label="Kategori">
            <option value="">Semua kategori</option>
            @foreach ($categories as $c)
                <option value="{{ $c->value }}" @selected($category === $c)>{{ $c->label() }}</option>
            @endforeach
        </select>
        <select name="device" aria-label="Perangkat">
            <option value="">Semua perangkat</option>
            @foreach ($devices as $d)
                <option value="{{ $d->public_id }}" @selected($device?->is($d))>{{ $d->displayLabel() }}</option>
            @endforeach
        </select>
        @if ($severity)<input type="hidden" name="severity" value="{{ $severity->value }}">@endif
        <label>Dari <input type="date" name="from" value="{{ $range->inputFrom() }}" max="{{ $range->inputTo() }}"></label>
        <label>Sampai <input type="date" name="to" value="{{ $range->inputTo() }}"></label>
        <button type="submit" class="btn">Terapkan</button>
        @foreach (['24h' => '24 jam', '7d' => '7 hari', '30d' => '30 hari'] as $key => $label)
            <button type="submit" name="preset" value="{{ $key }}" class="btn btn-small">{{ $label }}</button>
        @endforeach
    </form>

    <section class="cards">
        @foreach ([\App\Enums\EventSeverity::Critical, \App\Enums\EventSeverity::Warning, \App\Enums\EventSeverity::Info] as $s)
            <a class="card card-link @if ($severity === $s) card-active @endif"
               href="{{ route('events.index', $query + ($severity === $s ? [] : ['severity' => $s->value])) }}">
                <div class="card-label">{{ $s->label() }}</div>
                <div class="card-value">{{ (int) ($counts[$s->value] ?? 0) }}</div>
            </a>
        @endforeach
    </section>
    <p class="muted">
        Rentang: {{ $range->label() }} (waktu {{ config('netwatch.display_timezone') }}) · diperbarui otomatis tiap 30 detik ·
        klik kartu untuk menyaring tingkat keparahan
    </p>

    <table class="table">
        <thead><tr><th>Waktu</th><th>Tingkat</th><th>Kategori</th><th>Perangkat</th><th>Pesan</th></tr></thead>
        <tbody>
        @forelse ($events as $event)
            <tr>
                <td>{{ $event->occurred_at->toDisplay() }}</td>
                <td><span class="badge {{ ['info' => '', 'warning' => 'badge-pending', 'critical' => 'badge-failed'][$event->severity->value] }}">{{ $event->severity->label() }}</span></td>
                <td>{{ $event->category->label() }}</td>
                <td>
                    @if ($event->device)
                        <a href="{{ route('events.index', ['device' => $event->device->public_id] + $query) }}">{{ $event->device->displayLabel() }}</a>
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
                <td>
                    <details>
                        <summary>{{ $event->message }}</summary>
                        @if ($event->hint())
                            <p><strong>Saran troubleshooting:</strong> {{ $event->hint() }}</p>
                        @endif
                        <p class="muted">
                            Kode: <code>{{ $event->code }}</code>
                            @if ($event->user) · Oleh: {{ $event->user->name }} @endif
                            @if ($event->device) · <a href="{{ route('devices.show', $event->device) }}">Buka perangkat</a> ·
                                <a href="{{ route('devices.uptime', $event->device) }}">Riwayat uptime</a> ·
                                <a href="{{ route('devices.bandwidth', $event->device) }}">Bandwidth</a>
                            @endif
                        </p>
                        @if ($event->details)
                            <pre class="muted">{{ json_encode($event->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        @endif
                    </details>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">Tidak ada event pada rentang dan filter ini.</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $events->links('pagination.pages') }}
@endsection
