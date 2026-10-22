@extends('layouts.app')

@section('title', 'Dashboard')

@section('main-attrs')data-page="dashboard" data-autoreload data-refresh-seconds="30"@endsection

@section('content')
    <h1>Dashboard</h1>

    <section class="cards">
        <a class="card card-link" href="{{ route('devices.index') }}"><div class="card-label">Total perangkat</div><div class="card-value">{{ $total }}</div></a>
        <a class="card card-link" href="{{ route('devices.index', ['status' => 'online']) }}"><div class="card-label">Online</div><div class="card-value ok">{{ $online }}</div></a>
        <a class="card card-link" href="{{ route('devices.index', ['status' => 'offline']) }}"><div class="card-label">Offline</div><div class="card-value {{ $offline > 0 ? 'bad' : '' }}">{{ $offline }}</div></a>
        <div class="card"><div class="card-label">Perintah berjalan</div><div class="card-value">{{ $pendingCommands }}</div></div>
    </section>

    <div class="two-col">
        <section>
            <h2>Perangkat offline</h2>
            @forelse ($offlineDevices as $device)
                <div class="list-row">
                    <a href="{{ route('devices.show', $device) }}">{{ $device->displayLabel() }}</a>
                    <span class="muted">{{ $device->last_seen_at?->diffForHumans() ?? 'belum pernah terhubung' }}</span>
                </div>
            @empty
                <p class="muted">Semua perangkat online.</p>
            @endforelse
        </section>

        <section>
            <h2>Perintah terbaru</h2>
            @forelse ($recentCommands as $command)
                <div class="list-row">
                    <span>
                        {{ $command->type->label() }} →
                        <a href="{{ route('devices.show', $command->device) }}">{{ $command->device->displayLabel() }}</a>
                    </span>
                    <span class="badge badge-{{ $command->status->value }}">{{ $command->status->label() }}</span>
                </div>
            @empty
                <p class="muted">Belum ada perintah. Buka halaman perangkat untuk mengirim perintah.</p>
            @endforelse
        </section>
    </div>
@endsection
