@extends('layouts.public')

@section('title', 'Tiket terkirim')

@section('content')
    <h1>Tiket terkirim</h1>
    <p>Nomor tiket Anda: <strong>{{ $number }}</strong></p>
    <p class="muted">Tim IT akan menanganinya. Jika Anda mengisi email, kabar penyelesaian dikirim ke sana; bila tidak, teknisi akan menghubungi Anda atau muncul pemberitahuan di komputer ini.</p>
    <a class="btn" href="{{ route('tickets.public.create') }}">Buat tiket lain</a>
@endsection
