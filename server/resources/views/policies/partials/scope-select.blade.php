{{-- Pilihan cakupan policy. Variabel: $selected ("all", "group:ID", "device:ID"); memakai $groups dan $devices dari halaman. --}}
<select name="scope" aria-label="Cakupan">
    <option value="all" @selected($selected === 'all')>Semua perangkat</option>
    <optgroup label="Grup">
        @foreach ($groups as $g)
            <option value="group:{{ $g->id }}" @selected($selected === 'group:'.$g->id)>{{ $g->name }}</option>
        @endforeach
    </optgroup>
    <optgroup label="Perangkat">
        @foreach ($devices as $d)
            <option value="device:{{ $d->id }}" @selected($selected === 'device:'.$d->id)>{{ $d->displayLabel() }}</option>
        @endforeach
    </optgroup>
</select>
