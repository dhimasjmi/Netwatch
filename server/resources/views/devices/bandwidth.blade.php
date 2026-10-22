@extends('layouts.app')

@section('title', 'Bandwidth '.$device->displayLabel())

@section('main-attrs')data-page="bandwidth" data-data-url="{{ $dataUrl }}" data-refresh-seconds="30" data-timezone="{{ config('netwatch.display_timezone') }}"@endsection

@section('content')
    <div class="page-head">
        <h1>Bandwidth: {{ $device->displayLabel() }}</h1>
        <div class="actions"><a class="btn" href="{{ route('devices.bandwidth.csv', ['device' => $device] + request()->only(['from', 'to', 'preset'])) }}">Unduh CSV</a> <a class="btn" href="{{ route('devices.show', $device) }}">Kembali ke perangkat</a></div>
    </div>

    @include('partials.range-filter', ['range' => $range, 'action' => route('devices.bandwidth', $device)])
    <p class="muted">
        Rentang: {{ $range->label() }} (waktu {{ config('netwatch.display_timezone') }}) ·
        <span id="bw-meta">memuat…</span> · diperbarui otomatis tiap 30 detik
    </p>

    <p id="bw-empty" class="muted" hidden>Belum ada data bandwidth pada rentang ini. Data muncul setelah agent versi 1.1.0 atau lebih baru mengirim heartbeat.</p>
    <div id="bw-container"></div>
@endsection

@push('scripts')
    <script src="{{ asset('js/bandwidth.js') }}" defer></script>
@endpush
