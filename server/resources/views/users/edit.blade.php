@extends('layouts.app')

@section('title', 'Ubah pengguna')

@section('content')
    <h1>Ubah pengguna</h1>

    <form method="POST" action="{{ route('users.update', $user) }}" class="form">
        @csrf
        @method('PATCH')
        <label>Nama <input type="text" name="name" value="{{ old('name', $user->name) }}" required maxlength="100"></label>
        <label>Email <input type="email" name="email" value="{{ old('email', $user->email) }}" required maxlength="255"></label>
        <label>Peran
            <select name="role" required>
                @foreach ($roles as $r)
                    <option value="{{ $r->value }}" @selected(old('role', $user->role->value) === $r->value)>{{ $r->label() }}</option>
                @endforeach
            </select>
        </label>
        <p class="muted">Mengubah peran akan mencabut sesi login pengguna tersebut.</p>
        <div>
            <button type="submit" class="btn btn-primary">Simpan</button>
            <a class="btn" href="{{ route('users.index') }}">Batal</a>
        </div>
    </form>

    <h2>Reset kata sandi</h2>
    <form method="POST" action="{{ route('users.password', $user) }}" class="form" autocomplete="off"
          data-confirm="Ganti kata sandi? Semua sesi pengguna ini akan dicabut.">
        @csrf
        @method('PUT')
        <label>Kata sandi baru <input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
        <label>Ulangi kata sandi <input type="password" name="password_confirmation" required autocomplete="new-password"></label>
        <div><button type="submit" class="btn btn-danger">Ganti kata sandi</button></div>
    </form>
@endsection
