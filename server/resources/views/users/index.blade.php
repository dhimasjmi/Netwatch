@extends('layouts.app')

@section('title', 'Pengguna')

@section('content')
    <h1>Pengguna</h1>

    <form method="GET" class="filters">
        <input type="search" name="q" value="{{ $search }}" placeholder="Cari nama atau email" aria-label="Cari">
        <select name="role" aria-label="Peran">
            <option value="">Semua peran</option>
            @foreach ($roles as $r)
                <option value="{{ $r->value }}" @selected($role === $r)>{{ $r->label() }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn">Filter</button>
        <a class="btn btn-primary" href="{{ route('users.create') }}">Tambah pengguna</a>
    </form>

    <table class="table">
        <thead><tr><th>Nama</th><th>Email</th><th>Peran</th><th>Status</th><th>Dibuat</th><th></th></tr></thead>
        <tbody>
        @forelse ($users as $user)
            <tr>
                <td>{{ $user->name }} @if ($user->is(auth()->user()))<span class="badge">Anda</span>@endif</td>
                <td>{{ $user->email }}</td>
                <td>{{ $user->role->label() }}</td>
                <td>
                    @if ($user->is_active)
                        <span class="badge badge-ok">Aktif</span>
                    @else
                        <span class="badge badge-off">Nonaktif</span>
                    @endif
                </td>
                <td class="muted">{{ $user->created_at?->toDisplay() }}</td>
                <td class="actions">
                    <a class="btn btn-small" href="{{ route('users.edit', $user) }}">Ubah</a>
                    @unless ($user->is(auth()->user()))
                        <form method="POST" action="{{ route('users.active', $user) }}" class="inline-form"
                              @if ($user->is_active) data-confirm="Nonaktifkan akun ini? Sesi aktifnya akan dicabut." @endif>
                            @csrf
                            @method('PATCH')
                            <button type="submit" @class(['btn btn-small', 'btn-danger' => $user->is_active])>
                                {{ $user->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                            </button>
                        </form>
                    @endunless
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">Tidak ada pengguna yang cocok.</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $users->links('pagination.pages') }}
@endsection
