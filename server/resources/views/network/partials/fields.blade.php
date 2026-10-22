@php($t = $target ?? null)
<input type="text" name="name" value="{{ old('name', $t?->name) }}" placeholder="Nama (mis. Core Switch)" required maxlength="100" aria-label="Nama">
<input type="text" name="address" value="{{ old('address', $t?->address) }}" placeholder="Alamat IP (mis. 10.0.0.1)" required maxlength="45" inputmode="decimal" aria-label="Alamat IP">
<select name="kind" aria-label="Jenis">
    @foreach ($kinds as $k)<option value="{{ $k->value }}" @selected(old('kind', $t?->kind->value ?? 'other') === $k->value)>{{ $k->label() }}</option>@endforeach
</select>
<select name="method" aria-label="Cara cek">
    <option value="icmp" @selected(old('method', $t?->method ?? 'icmp') === 'icmp')>Ping (ICMP)</option>
    <option value="tcp" @selected(old('method', $t?->method) === 'tcp')>Port TCP</option>
</select>
<input type="number" name="port" value="{{ old('port', $t?->port) }}" placeholder="Port (untuk TCP)" min="1" max="65535" aria-label="Port TCP">
<input type="text" name="notes" value="{{ old('notes', $t?->notes) }}" placeholder="Catatan (opsional)" maxlength="255" aria-label="Catatan">
