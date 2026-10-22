@extends('layouts.app')

@section('title', 'Audit Log')

@section('content')
    <h1>Audit log</h1>

    <form method="GET" class="filters">
        <select name="action" aria-label="Aksi">
            <option value="">Semua aksi</option>
            @foreach ($actions as $action)
                <option value="{{ $action }}" @selected($selectedAction === $action)>{{ $action }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn">Filter</button>
    </form>

    <table class="table">
        <thead><tr><th>Waktu</th><th>Aksi</th><th>Pengguna</th><th>Perangkat</th><th>IP</th><th>Detail</th></tr></thead>
        <tbody>
        @forelse ($logs as $log)
            <tr>
                <td>{{ $log->created_at->toDisplay() }}</td>
                <td><code>{{ $log->action }}</code></td>
                <td>{{ $log->user?->name ?? '—' }}</td>
                <td>{{ $log->device?->displayLabel() ?? '—' }}</td>
                <td>{{ $log->ip_address ?? '—' }}</td>
                <td class="muted">{{ $log->metadata ? json_encode($log->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">Belum ada catatan.</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $logs->links('pagination.pages') }}
@endsection
