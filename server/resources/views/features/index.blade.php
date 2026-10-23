@extends('layouts.app')

@section('title', 'Pengaturan fitur')

@section('main-attrs')data-page="features"@endsection

@section('content')
    <h1>Pengaturan fitur</h1>
    <p class="muted">
        Hidupkan atau matikan fitur untuk seluruh pengguna. Data yang sudah tersimpan tidak dihapus dan akan tampil lagi bila fitur dihidupkan kembali.
        Perubahan berlaku seketika di server; agent PC menyesuaikan pada heartbeat berikutnya (sekitar 30 detik). Setiap perubahan tercatat di Audit Log.
    </p>

    <div class="feature-list">
        @foreach ($features as $feature)
            @php($on = $flags->enabled($feature))
            @php($record = $records[$feature->value] ?? null)
            <section class="card feature-card">
                <div class="feature-head">
                    <h2>{{ $feature->label() }}</h2>
                    <span class="badge {{ $on ? 'badge-ok' : 'badge-off' }}">{{ $on ? 'AKTIF' : 'NONAKTIF' }}</span>
                </div>
                <p>{{ $feature->description() }}</p>
                <p class="muted">Bila dimatikan: {{ $feature->effect() }}</p>
                <p class="muted">
                    @if ($record)
                        Diatur dari panel oleh {{ $users[$record->updated_by] ?? 'pengguna yang sudah dihapus' }}@if ($record->updated_at), {{ $record->updated_at->toDisplay() }}@endif.
                    @else
                        Mengikuti pengaturan bawaan (.env): {{ $feature->defaultEnabled() ? 'aktif' : 'nonaktif' }}.
                    @endif
                </p>
                <form method="POST" action="{{ route('features.update', $feature->value) }}" class="filters">
                    @csrf @method('PATCH')
                    @if ($on)
                        <button type="submit" name="action" value="disable" class="btn btn-danger">Matikan</button>
                    @else
                        <button type="submit" name="action" value="enable" class="btn btn-primary">Hidupkan</button>
                    @endif
                    @if ($record)
                        <button type="submit" name="action" value="reset" class="btn btn-small">Kembali ke bawaan (.env)</button>
                    @endif
                </form>
            </section>
        @endforeach
    </div>
@endsection
