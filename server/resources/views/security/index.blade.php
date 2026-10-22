@extends('layouts.app')

@section('title', 'Keamanan')

@section('content')
    <div class="page-head">
        <h1>Pemeriksaan keamanan</h1>
        <span class="badge {{ ['ok' => 'badge-ok', 'warning' => 'badge-pending', 'fail' => 'badge-failed'][$overall->value] }}">{{ $overall->label() }}</span>
    </div>
    <p class="muted">Pemeriksaan hanya membaca konfigurasi dan data; tidak ada yang diubah. Jalankan juga <code>php artisan netwatch:security-check</code> dan <code>composer audit</code> secara berkala (lihat docs/KEAMANAN.md).</p>

    <table class="table">
        <thead><tr><th>Pemeriksaan</th><th>Status</th><th>Keterangan</th></tr></thead>
        <tbody>
        @foreach ($checks as $check)
            <tr>
                <td>{{ $check->title }}</td>
                <td><span class="badge {{ ['ok' => 'badge-ok', 'warning' => 'badge-pending', 'fail' => 'badge-failed'][$check->status->value] }}">{{ $check->status->label() }}</span></td>
                <td>
                    {{ $check->detail }}
                    @if ($check->advice && $check->status !== \App\Enums\CheckStatus::Ok)
                        <div class="muted"><strong>Saran:</strong> {{ $check->advice }}</div>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
