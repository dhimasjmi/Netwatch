@extends('layouts.app')

@section('title', 'Dashboard')

@section('main-attrs')data-page="dashboard" data-autoreload data-refresh-seconds="30"@endsection

@section('content')
    <div class="page-head">
        <h1>Dashboard</h1>
        <div class="actions">
            <button type="button" class="btn btn-small fs-toggle" data-fullscreen-toggle aria-pressed="false" title="Tampilkan layar penuh untuk monitor (Esc untuk keluar)">Layar penuh</button>
        </div>
    </div>

    <details class="dash-settings kiosk-hide" {{ $errors->has('widgets') || $errors->has('action') ? 'open' : '' }}>
        <summary>Atur tampilan dashboard</summary>
        <form method="POST" action="{{ route('dashboard.widgets') }}" class="dash-settings-form">
            @csrf
            <p class="muted">Pilih data yang ingin ditampilkan. Pilihan ini hanya berlaku untuk akun Anda.</p>
            <div class="dash-settings-groups">
                @foreach (collect($available)->groupBy(fn ($w) => $w->section()) as $section => $items)
                    <fieldset>
                        <legend>{{ $section }}</legend>
                        @foreach ($items as $w)
                            <label class="dash-check"><input type="checkbox" name="widgets[]" value="{{ $w->value }}" @checked(in_array($w->value, $chosen, true))> {{ $w->label() }}</label>
                        @endforeach
                    </fieldset>
                @endforeach
            </div>
            <div class="dash-settings-actions">
                <button type="submit" name="action" value="save" class="btn btn-primary">Simpan tampilan</button>
                @if ($isCustom)
                    <button type="submit" name="action" value="reset" class="btn">Kembalikan bawaan</button>
                @endif
            </div>
        </form>
    </details>

    @if ($selected === [])
        <p class="muted">Belum ada widget yang dipilih. Buka "Atur tampilan dashboard" di atas untuk memilih data yang ingin ditampilkan.</p>
    @endif

    @if ($summary !== [])
        <section class="cards">
            @foreach ($summary as $w)
                <div class="dash-contents" data-shown="{{ $w->value }}">@include('dashboard.widgets.'.$w->value, ['d' => $data[$w->value] ?? null])</div>
            @endforeach
        </section>
    @endif

    @if ($panels !== [])
        <div class="dash-grid">
            @foreach ($panels as $w)
                <section class="dash-panel" data-shown="{{ $w->value }}">
                    <div class="dash-panel-head">
                        <h2>{{ $w->label() }}</h2>
                        <a href="{{ route($w->route()) }}">Buka {{ $w->section() }} →</a>
                    </div>
                    @include('dashboard.widgets.'.$w->value, ['d' => $data[$w->value] ?? null])
                </section>
            @endforeach
        </div>
    @endif
@endsection
