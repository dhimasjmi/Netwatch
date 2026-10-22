{{-- Filter rentang tanggal. Variabel: $range (DateRange), $action (URL), $hidden (opsional, array nama => nilai) --}}
<form method="GET" action="{{ $action }}" class="filters">
    @foreach ($hidden ?? [] as $name => $value)
        @if ($value !== null && $value !== '')
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endif
    @endforeach
    <label>Dari <input type="date" name="from" value="{{ $range->inputFrom() }}" max="{{ $range->inputTo() }}"></label>
    <label>Sampai <input type="date" name="to" value="{{ $range->inputTo() }}"></label>
    <button type="submit" class="btn">Terapkan</button>
    @foreach (['24h' => '24 jam', '7d' => '7 hari', '30d' => '30 hari'] as $key => $label)
        <button type="submit" name="preset" value="{{ $key }}" class="btn btn-small">{{ $label }}</button>
    @endforeach
</form>
