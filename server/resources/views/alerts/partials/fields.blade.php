{{-- Kolom bersama form buat/ubah aturan alert. Variabel: $rule (AlertRule|null), $groups, $devices dari halaman. --}}
<select name="metric" aria-label="Metrik">
    @foreach (\App\Enums\AlertMetric::cases() as $m)
        <option value="{{ $m->value }}" @selected(old('metric', $rule?->metric->value) === $m->value)>{{ $m->label() }}</option>
    @endforeach
</select>
<label>≥ <input type="number" name="threshold" value="{{ old('threshold', $rule?->threshold ?? 90) }}" min="1" max="100" required class="num-input" aria-label="Ambang persen"> %</label>
<label>selama <input type="number" name="duration_minutes" value="{{ old('duration_minutes', $rule?->duration_minutes ?? 10) }}" min="1" max="1440" required class="num-input" aria-label="Durasi menit"> menit</label>
<select name="severity" aria-label="Tingkat">
    <option value="warning" @selected(old('severity', $rule?->severity->value) === 'warning')>Peringatan</option>
    <option value="critical" @selected(old('severity', $rule?->severity->value) === 'critical')>Kritis</option>
</select>
@include('policies.partials.scope-select', ['selected' => old('scope', $rule?->scopeValue() ?? 'all')])
