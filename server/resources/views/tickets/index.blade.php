@extends('layouts.app')

@section('title', 'Tiket')

@section('main-attrs')data-page="tickets"@endsection

@section('content')
    <div class="page-head">
        <h1>Tiket bantuan</h1>
        <div class="actions">
            <a class="btn" href="{{ route('tickets.report') }}">Laporan penilaian</a>
            <a class="btn" href="{{ route('tickets.public.create') }}" target="_blank" rel="noopener">Form pengguna</a>
        </div>
    </div>

    <form method="GET" class="filters">
        <select name="status" aria-label="Status">
            <option value="active" @selected($status === 'active')>Belum selesai</option>
            <option value="open" @selected($status === 'open')>Baru</option>
            <option value="in_progress" @selected($status === 'in_progress')>Diproses</option>
            <option value="resolved" @selected($status === 'resolved')>Selesai</option>
            <option value="all" @selected($status === 'all')>Semua</option>
        </select>
        <label><input type="checkbox" name="mine" value="1" @checked($mine)> Milik saya</label>
        <input type="search" name="q" value="{{ $search }}" placeholder="Cari judul atau nama pemohon…" maxlength="100" aria-label="Cari">
        <button type="submit" class="btn">Terapkan</button>
    </form>

    <table class="table">
        <thead><tr><th>No.</th><th>Judul</th><th>Pemohon</th><th>Perangkat</th><th>Prioritas</th><th>Status</th><th>Penanggung jawab</th><th>Dibuat</th></tr></thead>
        <tbody>
        @forelse ($tickets as $t)
            <tr>
                <td><a href="{{ route('tickets.show', $t) }}">{{ $t->number() }}</a></td>
                <td>{{ $t->subject }}</td>
                <td>{{ $t->requester_name }}</td>
                <td>@if ($t->device)<a href="{{ route('devices.show', $t->device) }}">{{ $t->device->displayLabel() }}</a>@else — @endif</td>
                <td>{{ $t->priority->label() }}</td>
                <td><span class="badge {{ $t->status->value === 'resolved' ? 'badge-ok' : ($t->status->value === 'open' ? 'badge-pending' : '') }}">{{ $t->status->label() }}</span></td>
                <td>{{ $t->assignee?->name ?? '—' }}</td>
                <td class="muted">{{ $t->created_at->toDisplay() }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">Tidak ada tiket yang cocok.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $tickets->links('pagination.pages') }}

    <h2>Buat tiket atas nama pengguna</h2>
    <p class="muted">Untuk laporan lewat telepon atau lisan.</p>
    <form method="POST" action="{{ route('tickets.store') }}" class="stack">
        @csrf
        <label>Nama pemohon <input type="text" name="requester_name" value="{{ old('requester_name') }}" maxlength="100" required></label>
        <label>Email (opsional) <input type="email" name="requester_email" value="{{ old('requester_email') }}" maxlength="150"></label>
        <label>Perangkat (opsional)
            <select name="device">
                <option value="">—</option>
                @foreach ($devices as $d)
                    <option value="{{ $d->public_id }}" @selected(old('device') === $d->public_id)>{{ $d->displayLabel() }}</option>
                @endforeach
            </select>
        </label>
        <label>Jenis
            <select name="category">
                @foreach ($categories as $c)<option value="{{ $c->value }}" @selected(old('category', 'other') === $c->value)>{{ $c->label() }}</option>@endforeach
            </select>
        </label>
        <label>Prioritas
            <select name="priority">
                @foreach ($priorities as $p)<option value="{{ $p->value }}" @selected(old('priority', 'normal') === $p->value)>{{ $p->label() }}</option>@endforeach
            </select>
        </label>
        <label>Judul <input type="text" name="subject" value="{{ old('subject') }}" maxlength="150" required></label>
        <label>Penjelasan <textarea name="description" rows="4" maxlength="2000" required>{{ old('description') }}</textarea></label>
        <button type="submit" class="btn btn-primary">Buat tiket</button>
    </form>
@endsection
