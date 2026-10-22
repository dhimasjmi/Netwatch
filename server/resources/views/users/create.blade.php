@extends('layouts.app')

@section('title', 'Tambah pengguna')

@section('content')
    <h1>Tambah pengguna</h1>

    <form method="POST" action="{{ route('users.store') }}" class="form" autocomplete="off">
        @csrf
        <label>Nama <input type="text" name="name" value="{{ old('name') }}" required maxlength="100"></label>
        <label>Email <input type="email" name="email" value="{{ old('email') }}" required maxlength="255"></label>
        <label>Peran
            <select name="role" required>
                @foreach ($roles as $r)
                    <option value="{{ $r->value }}" @selected(old('role', 'viewer') === $r->value)>{{ $r->label() }}</option>
                @endforeach
            </select>
        </label>
        <label>Kata sandi <input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
        <label>Ulangi kata sandi <input type="password" name="password_confirmation" required autocomplete="new-password"></label>
        <p class="muted">Minimal 12 karakter, kombinasi huruf besar, huruf kecil, dan angka.</p>
        <div>
            <button type="submit" class="btn btn-primary">Simpan</button>
            <a class="btn" href="{{ route('users.index') }}">Batal</a>
        </div>
    </form>
@endsection
