@extends('layouts.app')

@section('title', 'Perangkat')

@section('main-attrs')data-page="devices-index" data-live-url="{{ route('devices.live') }}"@endsection

@section('content')
    <h1>Perangkat</h1>

    <form method="GET" class="filters">
        <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Cari hostname…" aria-label="Cari hostname">
        <select name="group" aria-label="Grup">
            <option value="">Semua grup</option>
            @foreach ($groups as $group)
                <option value="{{ $group->id }}" @selected($filters['group'] === $group->id)>{{ $group->name }}</option>
            @endforeach
        </select>
        <select name="status" aria-label="Status">
            <option value="">Semua status</option>
            <option value="online" @selected($filters['status'] === 'online')>Online</option>
            <option value="offline" @selected($filters['status'] === 'offline')>Offline</option>
        </select>
        <button type="submit" class="btn">Filter</button>
    </form>

    <table class="table">
        <thead>
        <tr>
            <th>Status</th><th>Perangkat</th><th>Grup</th><th>OS</th><th>Pengguna</th>
            <th>CPU</th><th>RAM</th><th>Terakhir terlihat</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($devices as $row)
            @php($device = $row['device'])
            @php($live = $row['live'])
            <tr data-device-id="{{ $device->public_id }}">
                <td><span class="badge {{ $live['online'] ? 'badge-ok' : 'badge-off' }}" data-status>{{ $live['online'] ? 'Online' : 'Offline' }}</span></td>
                <td><a href="{{ route('devices.show', $device) }}">{{ $device->displayLabel() }}</a>@if ($device->isInMaintenance()) <span class="badge badge-pending">Maintenance</span>@endif</td>
                <td>{{ $device->group?->name ?? '—' }}</td>
                <td>{{ $device->os_family->label() }}</td>
                <td data-field="logged_in_user" data-format="text">{{ $live['logged_in_user'] ?? '—' }}</td>
                <td>
                    <progress max="100" value="{{ $live['cpu_percent'] ?? 0 }}" data-bar="cpu_percent"></progress>
                    <span data-field="cpu_percent" data-format="percent">{{ isset($live['cpu_percent']) ? $live['cpu_percent'].'%' : '—' }}</span>
                </td>
                <td>
                    <progress max="100" value="{{ $live['memory_percent'] ?? 0 }}" data-bar="memory_percent"></progress>
                    <span data-field="memory_percent" data-format="percent">{{ isset($live['memory_percent']) ? $live['memory_percent'].'%' : '—' }}</span>
                </td>
                <td class="muted" data-field="last_seen" data-format="text">{{ $live['last_seen'] ?? 'belum pernah' }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">Belum ada perangkat. Buat token pendaftaran lalu pasang agent di komputer user.</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $devices->links('pagination.pages') }}
@endsection
