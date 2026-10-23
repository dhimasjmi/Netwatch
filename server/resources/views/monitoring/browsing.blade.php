@extends('layouts.app')

@section('title', 'Browsing Monitoring')

@section('main-attrs')data-page="monitor-browsing" data-url="{{ route('monitor.browsing.data') }}" data-raw-days="{{ config('netwatch.retention.browsing_days') }}"@endsection

@section('content')
    @include('monitoring.tabs', ['active' => 'browsing'])
    @include('monitoring.partials.browsing', ['device' => null])
@endsection

@push('scripts')
    <script src="{{ asset('js/monitor-ui.js') }}" defer></script>
    <script src="{{ asset('js/monitor-browsing.js') }}" defer></script>
@endpush
