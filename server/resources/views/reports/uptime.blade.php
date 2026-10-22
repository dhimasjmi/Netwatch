@extends('layouts.app')

@section('title', 'Laporan Uptime')

@section('content')
    <div class="page-head">
        <h1>Laporan Uptime</h1>
        <div class="actions">
            <a class="btn" href="{{ route('reports.uptime.csv', $query) }}">Ekspor CSV</a>
        </div>
    </div>

    @include('partials.range-filter', ['range' => $range, 'action' => route('reports.uptime'), 'hidden' => ['group' => $groupId]])
    <form method="GET" action="{{ route('reports.uptime') }}" class="filters">
        <input type="hidden" name="from" value="{{ $range->inputFrom() }}">
        <input type="hidden" name="to" value="{{ $range->inputTo() }}">
        <select name="group" aria-label="Grup">
            <option value="">Semua grup</option>
            @foreach ($groups as $group)
                <option value="{{ $group->id }}" @selected($groupId === $group->id)>{{ $group->name }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-small">Terapkan grup</button>
    </form>
    <p class="muted">Rentang: {{ $range->label() }} (waktu {{ config('netwatch.display_timezone') }}). Diurutkan dari ketersediaan terendah.</p>

    <table class="table">
        <thead>
        <tr><th>Perangkat</th><th>Grup</th><th>Ketersediaan</th><th>Gangguan</th><th>Total downtime</th><th>Terlama</th><th></th></tr>
        </thead>
        <tbody>
        @forelse ($rows as $row)
            @php($availability = $row['summary']->availabilityPercent())
            <tr>
                <td><a href="{{ route('devices.uptime', $row['device']) }}">{{ $row['device']->displayLabel() }}</a></td>
                <td>{{ $row['device']->group?->name ?? '—' }}</td>
                <td class="{{ $availability === null ? 'muted' : ($availability >= 99 ? 'ok' : ($availability >= 95 ? '' : 'bad')) }}">
                    {{ $availability === null ? 'Tanpa data' : number_format($availability, 2).'%' }}
                </td>
                <td>{{ $row['summary']->outageCount }}</td>
                <td>{{ \App\Support\Duration::human($row['summary']->downSeconds) }}</td>
                <td>{{ $row['summary']->outageCount === 0 ? '—' : \App\Support\Duration::human($row['summary']->longestOutageSeconds) }}</td>
                <td><a href="{{ route('devices.uptime', array_merge(['device' => $row['device']], $query)) }}">Detail</a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">Belum ada perangkat.</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
