@extends('layouts.app')

@section('title', 'Browsing · '.$device->displayLabel())

@section('main-attrs')data-page="monitor-browsing" data-url="{{ route('devices.browsing.data', $device) }}" data-device="{{ $device->public_id }}" data-raw-days="{{ config('netwatch.retention.browsing_days') }}"@endsection

@section('content')
    <p class="back-link"><a href="{{ route('devices.show', $device) }}">← Kembali ke {{ $device->displayLabel() }}</a>
        @feature('browsing')@can('manage-fleet') · <a href="{{ route('monitor.browsing') }}">Browsing semua perangkat</a>@endcan @endfeature</p>
    @include('monitoring.partials.browsing', ['device' => $device])
@endsection

@push('scripts')
    <script src="{{ asset('js/monitor-ui.js') }}" defer></script>
    <script src="{{ asset('js/monitor-browsing.js') }}" defer></script>
@endpush
