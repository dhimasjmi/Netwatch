@extends('layouts.app')

@section('title', 'Tren '.$device->displayLabel())

@section('main-attrs')data-page="metrics" data-refresh-seconds="30"@endsection

@section('content')
    <div class="page-head">
        <h1>Tren sumber daya: {{ $device->displayLabel() }}</h1>
        <div class="actions"><a class="btn" href="{{ route('devices.show', $device) }}">Kembali ke perangkat</a></div>
    </div>

    <nav class="tabs" aria-label="Rentang waktu">
        @foreach ($ranges as $r)
            <a href="{{ route('devices.metrics', ['device' => $device, 'range' => $r]) }}" @class(['active' => $r === $range]) @if($r === $range) aria-current="page" @endif>{{ $r }}</a>
        @endforeach
    </nav>
    <p class="muted">{{ $startLabel }} sampai {{ $endLabel }} (waktu {{ config('netwatch.display_timezone') }}) · rata-rata per interval · diperbarui otomatis tiap 30 detik</p>

    @foreach ($charts as $chart)
        <section class="card trend">
            <h2>{{ $chart['label'] }}</h2>
            @if ($chart['summary'] === null)
                <p class="muted">Belum ada data pada rentang ini.</p>
            @else
                <svg class="trend-svg" viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" role="img"
                     aria-label="Grafik {{ $chart['label'] }}: terakhir {{ $chart['summary']['last'] }}%, rata-rata {{ $chart['summary']['avg'] }}%, tertinggi {{ $chart['summary']['max'] }}%">
                    <line class="trend-grid" x1="0" y1="{{ $height / 2 }}" x2="{{ $width }}" y2="{{ $height / 2 }}"/>
                    <line class="trend-grid" x1="0" y1="{{ $height * 0.1 }}" x2="{{ $width }}" y2="{{ $height * 0.1 }}"/>
                    @foreach ($chart['segments'] as $points)
                        <polyline class="trend-line" points="{{ $points }}" fill="none"/>
                    @endforeach
                </svg>
                <p class="muted">Skala 0-100% (garis bantu 50% dan 90%). Terakhir <strong>{{ $chart['summary']['last'] }}%</strong> · rata-rata {{ $chart['summary']['avg'] }}% · terendah {{ $chart['summary']['min'] }}% · tertinggi {{ $chart['summary']['max'] }}%. Garis terputus berarti perangkat tidak mengirim data.</p>
            @endif
        </section>
    @endforeach
@endsection
