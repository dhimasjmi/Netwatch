@extends('layouts.public')

@section('title', 'Buat tiket bantuan')

@section('content')
    <h1>Buat tiket bantuan IT</h1>
    <p class="muted">Ceritakan masalah Anda. Tim IT akan menanganinya dan memberi kabar. Jangan menulis kata sandi di sini.</p>

    <form method="POST" action="{{ route('tickets.public.store') }}" class="stack">
        @csrf
        <input type="hidden" name="pc" value="{{ old('pc', $pc) }}">
        <div class="hp-field" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <label>Nama Anda
            <input type="text" name="requester_name" value="{{ old('requester_name') }}" maxlength="100" required autofocus>
        </label>
        <label>Email (opsional, untuk kabar penyelesaian)
            <input type="email" name="requester_email" value="{{ old('requester_email') }}" maxlength="150" autocomplete="email">
        </label>
        <label>Jenis masalah
            <select name="category" required>
                @foreach ($categories as $c)
                    <option value="{{ $c->value }}" @selected(old('category', 'other') === $c->value)>{{ $c->label() }}</option>
                @endforeach
            </select>
        </label>
        <label>Judul singkat
            <input type="text" name="subject" value="{{ old('subject') }}" maxlength="150" required>
        </label>
        <label>Penjelasan (apa yang terjadi, sejak kapan, pesan galat bila ada)
            <textarea name="description" rows="6" maxlength="2000" required>{{ old('description') }}</textarea>
        </label>
        <button type="submit" class="btn btn-primary">Kirim tiket</button>
    </form>

    <hr>
    <h2 id="kode">Sudah punya kode penilaian?</h2>
    <form method="POST" action="{{ route('feedback.lookup') }}" class="filters">
        @csrf
        <input type="text" name="code" placeholder="Kode 10 karakter" maxlength="30" class="code-box" aria-label="Kode penilaian">
        <button type="submit" class="btn">Beri penilaian</button>
    </form>
@endsection
