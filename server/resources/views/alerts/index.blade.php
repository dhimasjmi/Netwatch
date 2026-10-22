@extends('layouts.app')

@section('title', 'Alert')

@section('main-attrs')data-page="alerts" data-refresh-seconds="30"@endsection

@section('content')
    <h1>Alert ambang</h1>
    <p class="muted">
        Beri tahu bila CPU, RAM, atau disk perangkat melewati ambang selama durasi tertentu. Dievaluasi tiap menit dari riwayat heartbeat.
        Hasilnya muncul di Event Viewer (kategori Kinerja) dan dikirim ke Telegram/email bila notifikasi diaktifkan. Perangkat offline atau dalam mode maintenance tidak dinilai.
    </p>

    <h2>Sedang menyala ({{ $firing->count() }})</h2>
    @if ($firing->isEmpty())
        <p class="muted">Tidak ada alert yang menyala.</p>
    @else
        <table>
            <thead><tr><th>Perangkat</th><th>Aturan</th><th>Nilai terakhir</th><th>Sejak</th></tr></thead>
            <tbody>
            @foreach ($firing as $state)
                <tr>
                    <td><a href="{{ route('devices.show', $state->device) }}">{{ $state->device->displayLabel() }}</a></td>
                    <td>{{ $state->rule->name }} <span class="muted">({{ $state->rule->summary() }})</span></td>
                    <td>{{ $state->last_value !== null ? number_format($state->last_value, 1).'%' : '-' }}</td>
                    <td>{{ $state->firing_since->toDisplay() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <h2>Buat aturan baru</h2>
    <form method="POST" action="{{ route('alerts.store') }}" class="filters">
        @csrf
        <input type="text" name="name" value="{{ old('name') }}" placeholder="Nama (mis. CPU tinggi)" required maxlength="100" aria-label="Nama aturan">
        @include('alerts.partials.fields', ['rule' => null])
        <button type="submit" class="btn btn-primary">Buat aturan</button>
    </form>

    <h2>Aturan ({{ $rules->count() }})</h2>
    @forelse ($rules as $rule)
        <section class="card" id="rule-{{ $rule->id }}">
            <div class="page-head">
                <h3>{{ $rule->name }}
                    <span class="badge {{ $rule->is_enabled ? 'badge-ok' : '' }}">{{ $rule->is_enabled ? 'Aktif' : 'Nonaktif' }}</span>
                    <span class="badge {{ $rule->severity->value === 'critical' ? 'badge-failed' : '' }}">{{ $rule->severity->label() }}</span>
                    @if ($rule->firing_count > 0)<span class="badge badge-failed">{{ $rule->firing_count }} menyala</span>@endif
                </h3>
                <div class="actions">
                    <form method="POST" action="{{ route('alerts.toggle', $rule) }}" class="inline-form">
                        @csrf
                        <button type="submit" class="btn btn-small">{{ $rule->is_enabled ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                    </form>
                    <form method="POST" action="{{ route('alerts.destroy', $rule) }}" class="inline-form" data-confirm="Hapus aturan &quot;{{ $rule->name }}&quot;?">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-small btn-danger">Hapus</button>
                    </form>
                </div>
            </div>
            <p class="muted">{{ $rule->summary() }} · berlaku untuk <strong>{{ $rule->scopeLabel() }}</strong></p>
            <details>
                <summary>Ubah aturan</summary>
                <form method="POST" action="{{ route('alerts.update', $rule) }}" class="filters">
                    @csrf
                    @method('PATCH')
                    <input type="text" name="name" value="{{ $rule->name }}" required maxlength="100" aria-label="Nama aturan">
                    @include('alerts.partials.fields', ['rule' => $rule])
                    <button type="submit" class="btn btn-small">Simpan</button>
                </form>
            </details>
        </section>
    @empty
        <p class="muted">Belum ada aturan. Contoh yang umum: CPU ≥ 90% selama 10 menit, RAM ≥ 90% selama 15 menit, Disk ≥ 90% selama 60 menit.</p>
    @endforelse
@endsection
