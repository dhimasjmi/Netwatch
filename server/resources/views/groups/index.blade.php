@extends('layouts.app')

@section('title', 'Grup')

@section('content')
    <h1>Grup perangkat</h1>

    <form method="POST" action="{{ route('groups.store') }}" class="filters">
        @csrf
        <input type="text" name="name" placeholder="Nama grup (mis. Lab 1, Divisi Keuangan)" required maxlength="100" aria-label="Nama grup">
        <input type="text" name="description" placeholder="Deskripsi (opsional)" maxlength="1000" aria-label="Deskripsi">
        <button type="submit" class="btn btn-primary">Tambah grup</button>
    </form>

    <table class="table">
        <thead><tr><th>Nama</th><th>Deskripsi</th><th>Perangkat</th><th></th></tr></thead>
        <tbody>
        @forelse ($groups as $group)
            <tr>
                <td><a href="{{ route('devices.index', ['group' => $group->id]) }}">{{ $group->name }}</a></td>
                <td class="muted">{{ $group->description }}</td>
                <td>{{ $group->devices_count }}</td>
                <td>
                    <form method="POST" action="{{ route('groups.destroy', $group) }}" data-confirm="Hapus grup ini? Perangkat di dalamnya menjadi tanpa grup.">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-small btn-danger">Hapus</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">Belum ada grup.</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
