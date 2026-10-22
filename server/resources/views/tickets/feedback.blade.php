@extends('layouts.public')

@section('title', 'Penilaian layanan')

@section('content')
    @if ($done)
        <h1>Terima kasih!</h1>
        <p>Penilaian Anda untuk tiket {{ $feedback->ticket->number() }} sudah kami terima dan membantu kami memperbaiki layanan.</p>
    @elseif ($feedback === null)
        <h1>Tautan tidak berlaku</h1>
        <p class="muted">Kode penilaian ini tidak valid, sudah dipakai, atau sudah kedaluwarsa. Hubungi tim IT bila Anda masih ingin memberi penilaian.</p>
    @else
        <h1>Bagaimana penanganan tiket Anda?</h1>
        <p>Tiket {{ $feedback->ticket->number() }}: <strong>{{ $feedback->ticket->subject }}</strong></p>
        <form method="POST" action="{{ route('feedback.store', ['code' => $code]) }}" class="stack">
            @csrf
            <fieldset class="rating-options">
                <legend>Nilai layanan</legend>
                @foreach ([5 => 'Sangat puas', 4 => 'Puas', 3 => 'Cukup', 2 => 'Kurang', 1 => 'Buruk'] as $value => $label)
                    <label><input type="radio" name="rating" value="{{ $value }}" required @checked((int) old('rating') === $value)><strong>{{ $value }}</strong><span class="muted">{{ $label }}</span></label>
                @endforeach
            </fieldset>
            <label>Komentar (opsional)
                <textarea name="comment" rows="4" maxlength="1000">{{ old('comment') }}</textarea>
            </label>
            <button type="submit" class="btn btn-primary">Kirim penilaian</button>
        </form>
    @endif
@endsection
