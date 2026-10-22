@extends('layouts.app')

@section('title', $name)

@section('main-attrs')data-page="assets"@endsection

@section('content')
    <div class="page-head">
        <h1>{{ $name }}</h1>
        <div class="actions"><a class="btn" href="{{ route('assets.index', ['tab' => 'software']) }}">Kembali</a></div>
    </div>
    <table class="table">
        <thead><tr><th>Perangkat</th><th>Versi</th><th>Pertama terlihat</th></tr></thead>
        <tbody>
        @forelse ($rows as $r)
            <tr>
                <td><a href="{{ route('devices.assets', $r->device) }}">{{ $r->device->displayLabel() }}</a></td>
                <td>{{ $r->version !== '' ? $r->version : '—' }}</td>
                <td class="muted">{{ $r->first_seen_at->toDisplay() }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="muted">Tidak ada perangkat aktif yang memasang program ini.</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
