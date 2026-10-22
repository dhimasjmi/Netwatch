@extends('layouts.app')

@section('title', 'Policies')

@section('main-attrs')data-page="policies"@endsection

@section('content')
    <h1>Policies</h1>
    <p class="muted">
        Kelompokkan situs dan IP yang diblokir ke dalam policy bernama (mis. "Media sosial"), lalu tentukan berlaku untuk semua perangkat, satu grup, atau satu perangkat.
        Diterapkan oleh agent di Windows (Windows Firewall, file hosts, kebijakan Chrome/Edge) dan efektif untuk pengguna akun biasa (bukan Administrator).
    </p>

    <h2>Buat policy baru</h2>
    <form method="POST" action="{{ route('policies.store') }}" class="filters">
        @csrf
        <input type="text" name="name" value="{{ old('name') }}" placeholder="Nama policy (mis. Media sosial)" required maxlength="100" aria-label="Nama policy">
        <input type="text" name="description" value="{{ old('description') }}" placeholder="Keterangan (opsional)" maxlength="255" size="32" aria-label="Keterangan">
        @include('policies.partials.scope-select', ['selected' => old('scope', 'all')])
        <button type="submit" class="btn btn-primary">Buat policy</button>
    </form>

    @forelse ($policies as $policy)
        <section class="card policy-card" id="policy-{{ $policy->id }}">
            <div class="page-head">
                <h2>{{ $policy->name }}
                    <span class="badge {{ $policy->is_enabled ? 'badge-failed' : '' }}">{{ $policy->is_enabled ? 'Aktif (memblokir)' : 'Nonaktif' }}</span>
                </h2>
                <div class="actions">
                    <form method="POST" action="{{ route('policies.toggle', $policy) }}" class="inline-form">
                        @csrf
                        <button type="submit" class="btn btn-small">{{ $policy->is_enabled ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                    </form>
                    <form method="POST" action="{{ route('policies.destroy', $policy) }}" class="inline-form"
                          data-confirm="Hapus policy &quot;{{ $policy->name }}&quot; beserta {{ $policy->rules->count() }} targetnya? Pemblokiran dicabut pada heartbeat berikutnya.">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-small btn-danger">Hapus</button>
                    </form>
                </div>
            </div>
            <p class="muted">
                Berlaku untuk: <strong>{{ $policy->scopeLabel() }}</strong>
                @if ($policy->description) · {{ $policy->description }} @endif
            </p>

            @if ($policy->rules->isEmpty())
                <p class="muted">Belum ada target. Tambahkan situs atau IP di bawah.</p>
            @else
                <ul class="chips">
                    @foreach ($policy->rules as $rule)
                        <li>
                            <span class="chip-type">{{ $rule->type->label() }}</span>
                            <code>{{ $rule->value }}</code>
                            <form method="POST" action="{{ route('policies.entries.destroy', [$policy, $rule]) }}" class="inline-form"
                                  data-confirm="Hapus {{ $rule->value }} dari policy ini?">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="chip-remove" aria-label="Hapus {{ $rule->value }}">×</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form method="POST" action="{{ route('policies.entries.store', $policy) }}" class="stack policy-add">
                @csrf
                <label>Tambah situs atau IP
                    <textarea name="entries" rows="3" required placeholder="facebook.com&#10;instagram.com&#10;203.0.113.0/24" maxlength="20000">{{ old('entries') }}</textarea>
                </label>
                <span class="muted">Satu per baris (atau pisahkan dengan koma). Domain, <code>*.contoh.com</code>, IP, atau rentang CIDR dikenali otomatis.</span>
                <button type="submit" class="btn btn-primary">Tambahkan</button>
            </form>

            <details>
                <summary>Ubah nama / cakupan</summary>
                <form method="POST" action="{{ route('policies.update', $policy) }}" class="filters policy-edit">
                    @csrf
                    @method('PATCH')
                    <input type="text" name="name" value="{{ $policy->name }}" required maxlength="100" aria-label="Nama policy">
                    <input type="text" name="description" value="{{ $policy->description }}" maxlength="255" size="32" placeholder="Keterangan" aria-label="Keterangan">
                    @include('policies.partials.scope-select', ['selected' => $policy->scopeValue()])
                    <button type="submit" class="btn">Simpan</button>
                </form>
            </details>
        </section>
    @empty
        <p class="muted">Belum ada policy. Buat satu di atas, misalnya "Media sosial", lalu isi dengan facebook.com, instagram.com, dan lainnya.</p>
    @endforelse

    <h2>Status penerapan per perangkat</h2>
    <table class="table">
        <thead><tr><th>Perangkat</th><th>Status</th><th>Versi agent</th></tr></thead>
        <tbody>
        @foreach ($statuses as $row)
            <tr>
                <td><a href="{{ route('devices.show', $row['device']) }}">{{ $row['device']->displayLabel() }}</a></td>
                <td>
                    <span class="badge {{ ['ok' => 'badge-ok', 'error' => 'badge-failed', 'pending' => 'badge-pending', 'na' => ''][$row['state']] }}">{{ $row['label'] }}</span>
                </td>
                <td class="muted">{{ $row['device']->agent_version }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
