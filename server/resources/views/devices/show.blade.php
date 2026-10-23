@extends('layouts.app')

@section('title', $device->displayLabel())

@section('main-attrs')data-page="devices-show" data-refresh-seconds="5" data-live-url="{{ route('devices.show.live', $device) }}" data-command-url="{{ route('devices.commands.store', $device) }}" data-can-run="{{ auth()->user()->can('run-commands') ? '1' : '0' }}"@endsection

@section('content')
    @php($metrics = $device->latest_metrics ?? [])

    <div class="page-head">
        <h1>
            {{ $device->displayLabel() }}
            <span class="badge {{ $live['online'] ? 'badge-ok' : 'badge-off' }}" data-status>{{ $live['online'] ? 'Online' : 'Offline' }}</span>
            @if ($device->isInMaintenance())
                <span class="badge badge-pending">Maintenance</span>
            @endif
        </h1>
        <div class="actions">
            <a class="btn" href="{{ route('devices.uptime', $device) }}">Riwayat uptime</a>
            @feature('bandwidth')<a class="btn" href="{{ route('devices.bandwidth', $device) }}">Bandwidth</a>@endfeature
            <a class="btn" href="{{ route('devices.metrics', $device) }}">Tren CPU/RAM/Disk</a>
            @can('manage-fleet')@feature('inventory')<a class="btn" href="{{ route('devices.assets', $device) }}">Inventaris</a>@endfeature @endcan
            @can('manage-fleet')@feature('inventory')<a class="btn" href="{{ route('devices.inventory', $device) }}">Port &amp; layanan</a>@endfeature @endcan
            @can('manage-fleet')@feature('browsing')<a class="btn" href="{{ route('devices.browsing', $device) }}">Browsing</a>@endfeature @endcan
            <a class="btn" href="{{ route('events.index', ['device' => $device->public_id, 'preset' => '7d']) }}">Event</a>
            @can('run-commands')
                <button type="button" class="btn" data-command="refresh_processes">Muat ulang proses</button>
                <button type="button" class="btn" data-command="send_message">Kirim pesan</button>
            @endcan
            @can('power-control')
                <button type="button" class="btn btn-danger" data-command="restart_computer">Restart</button>
                <button type="button" class="btn btn-danger" data-command="shutdown_computer">Matikan</button>
            @endcan
        </div>
    </div>

    <section class="cards">
        <div class="card">
            <div class="card-label">CPU</div>
            <div class="card-value" data-field="cpu_percent" data-format="percent">{{ isset($live['cpu_percent']) ? $live['cpu_percent'].'%' : '—' }}</div>
            <progress max="100" value="{{ $live['cpu_percent'] ?? 0 }}" data-bar="cpu_percent"></progress>
            @if ($cpuPoints !== '')
                <svg class="spark" viewBox="0 0 240 48" preserveAspectRatio="none" role="img" aria-label="Tren CPU 1 jam terakhir"><polyline points="{{ $cpuPoints }}"/></svg>
            @endif
        </div>
        <div class="card">
            <div class="card-label">RAM</div>
            <div class="card-value" data-field="memory" data-format="memory">
                {{ isset($metrics['memory_total_mb']) ? number_format($metrics['memory_used_mb'] / 1024, 1).' / '.number_format($metrics['memory_total_mb'] / 1024, 1).' GB' : '—' }}
            </div>
            <progress max="100" value="{{ $live['memory_percent'] ?? 0 }}" data-bar="memory_percent"></progress>
            @if ($memoryPoints !== '')
                <svg class="spark" viewBox="0 0 240 48" preserveAspectRatio="none" role="img" aria-label="Tren RAM 1 jam terakhir"><polyline points="{{ $memoryPoints }}"/></svg>
            @endif
        </div>
        <div class="card">
            <div class="card-label">Disk</div>
            <div class="card-value" data-field="disk" data-format="disk">
                {{ isset($metrics['disk_total_gb']) ? $metrics['disk_used_gb'].' / '.$metrics['disk_total_gb'].' GB' : '—' }}
            </div>
            <progress max="100" value="{{ $live['disk_percent'] ?? 0 }}" data-bar="disk_percent"></progress>
        </div>
        <div class="card">
            <div class="card-label">Uptime</div>
            <div class="card-value" data-field="uptime_seconds" data-format="uptime">
                {{ isset($metrics['uptime_seconds']) ? \Carbon\CarbonInterval::seconds((int) $metrics['uptime_seconds'])->cascade()->locale('id')->forHumans(['parts' => 2, 'short' => true]) : '—' }}
            </div>
            <div class="muted">Terakhir terlihat: <span data-field="last_seen" data-format="text">{{ $live['last_seen'] ?? 'belum pernah' }}</span></div>
        </div>
    </section>

    <section class="two-col">
        <div>
            <h2>Informasi</h2>
            <dl class="details">
                <dt>Hostname</dt><dd>{{ $device->hostname }}</dd>
                <dt>Pengguna aktif</dt><dd data-field="logged_in_user" data-format="text">{{ $live['logged_in_user'] ?? '—' }}</dd>
                <dt>Sistem operasi</dt><dd>{{ $device->os_family->label() }} {{ $device->os_version }}</dd>
                <dt>Alamat IP</dt><dd>{{ $device->ip_address ?? '—' }}</dd>
                @if (!$live['online'] && $device->ping_checked_at !== null)
                    <dt>Ping dari server</dt>
                    <dd>
                        @if ($device->ping_ok)
                            <span class="ok">Merespons{{ $device->ping_ms !== null ? ' ('.round($device->ping_ms, 1).' ms)' : '' }}</span>
                            <span class="muted">— PC menyala, kemungkinan layanan agent berhenti.</span>
                        @else
                            <span class="bad">Tidak merespons</span>
                            <span class="muted">— PC mati/sleep atau terputus dari jaringan.</span>
                        @endif
                        <div class="muted">Dicek {{ $device->ping_checked_at->toDisplay() }}</div>
                    </dd>
                @endif
                @if ($device->isInMaintenance())
                    <dt>Maintenance</dt>
                    <dd>Sampai {{ $device->maintenance_until->toDisplay() }}@if ($device->maintenance_note) <span class="muted">— {{ $device->maintenance_note }}</span>@endif</dd>
                @endif
                <dt>MAC</dt><dd>{{ $device->mac_address ?? '—' }}</dd>
                <dt>Versi agent</dt><dd>{{ $device->agent_version }}</dd>
                <dt>Policies</dt><dd>@can('manage-fleet')<a href="{{ route('policies.index') }}">{{ $policyStatus['label'] }}</a>@else{{ $policyStatus['label'] }}@endcan</dd>
                <dt>Grup</dt><dd>{{ $device->group?->name ?? '—' }}</dd>
                <dt>Terdaftar</dt><dd>{{ $device->enrolled_at->toDisplay() }}</dd>
            </dl>
        </div>

        @can('manage-fleet')
            <div>
                <h2>Pengaturan</h2>
                <form method="POST" action="{{ route('devices.update', $device) }}" class="stack">
                    @csrf
                    @method('PATCH')
                    <label>Nama tampilan
                        <input type="text" name="display_name" value="{{ old('display_name', $device->display_name) }}" maxlength="255" placeholder="{{ $device->hostname }}">
                    </label>
                    <label>Grup
                        <select name="device_group_id">
                            <option value="">Tanpa grup</option>
                            @foreach ($groups as $group)
                                <option value="{{ $group->id }}" @selected($device->device_group_id === $group->id)>{{ $group->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </form>
                <h2>Mode maintenance</h2>
                @if ($device->isInMaintenance())
                    <form method="POST" action="{{ route('devices.maintenance.destroy', $device) }}" class="inline-form">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn">Akhiri maintenance</button>
                    </form>
                @else
                    <p class="muted">Tahan notifikasi offline saat PC sengaja dimatikan atau diservis. Pencatatan uptime tetap berjalan.</p>
                    <form method="POST" action="{{ route('devices.maintenance.store', $device) }}" class="stack">
                        @csrf
                        <label>Lama
                            <select name="hours">
                                @foreach (\App\Http\Requests\Web\StartMaintenanceRequest::HOURS as $h)
                                    <option value="{{ $h }}">{{ $h < 24 ? $h.' jam' : intdiv($h, 24).' hari' }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Catatan (opsional)
                            <input type="text" name="note" maxlength="255" placeholder="mis. ganti RAM">
                        </label>
                        <button type="submit" class="btn">Mulai maintenance</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('devices.destroy', $device) }}" data-confirm="Cabut akses perangkat ini? Agent tidak akan bisa terhubung lagi." class="inline-form">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">Cabut akses perangkat</button>
                </form>
            </div>
        @endcan
    </section>

    <section>
        <div class="page-head">
            <h2>Proses (Task Manager)</h2>
            <div class="actions">
                <span class="muted" id="snapshot-label">Belum ada data</span>
                <input type="search" id="process-filter" placeholder="Filter proses…" aria-label="Filter proses">
            </div>
        </div>
        <div class="table-scroll">
            <table class="table" id="process-table">
                <thead>
                <tr>
                    <th data-sort="name">Nama</th>
                    <th data-sort="pid">PID</th>
                    <th data-sort="user">Pengguna</th>
                    <th data-sort="cpu_seconds">CPU (dtk)</th>
                    <th data-sort="memory_mb">Memori (MB)</th>
                    <th></th>
                </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </section>

    <section>
        <h2>Riwayat perintah</h2>
        <table class="table" id="command-table">
            <thead><tr><th>Waktu</th><th>Perintah</th><th>Oleh</th><th>Status</th><th>Keterangan</th></tr></thead>
            <tbody>
            @foreach ($commands as $command)
                <tr>
                    <td>{{ $command->created_at?->toDisplay() }}</td>
                    <td>{{ $command->type->label() }}</td>
                    <td>{{ $command->requester?->name ?? '—' }}</td>
                    <td><span class="badge badge-{{ $command->status->value }}">{{ $command->status->label() }}</span></td>
                    <td class="muted">{{ $command->error_message }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
@endsection
