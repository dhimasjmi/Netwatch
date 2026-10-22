@extends('layouts.app')

@section('title', 'Riwayat browsing')

@section('main-attrs')data-page="browsing"@endsection

@section('content')
    <div class="page-head">
        <h1>Riwayat browsing</h1>
        <div class="actions"><a class="btn" href="{{ route('browsing.csv', $query) }}">Ekspor CSV</a></div>
    </div>

    @unless ($enabled)
        <div class="alert alert-error" role="alert">
            Pengumpulan riwayat browsing belum aktif. Setelah sesuai ketentuan perusahaan, set <code>NETWATCH_BROWSING_ENABLED=true</code> di .env
            lalu jalankan <code>php artisan config:clear</code>. Perlu agent 1.3.0 di komputer klien.
        </div>
    @endunless

    <p class="muted">
        Data sensitif: hanya administrator yang bisa melihat, dan setiap akses tercatat di Audit Log.
        Yang disimpan: {{ $detail === 'path' ? 'situs dan alamat halaman' : 'nama situs (domain)' }} tanpa kata pencarian/query, serta IP tujuan koneksi keluar.
        "Koneksi" menunjukkan komputer terhubung ke IP itu (dengan nama domain bila diketahui), bukan jumlah halaman dibuka.
    </p>

    <p class="muted">
        Retensi: rincian per kunjungan disimpan {{ $rawDays }} hari, setelah itu dirangkum per hari (jumlah kunjungan per situs) dan disimpan {{ $dailyDays }} hari.
        Pada data yang sudah dirangkum, hanya jumlah dan situs yang tersedia (tanpa URL lengkap dan jam persis).
    </p>

    <form method="GET" class="filters">
        <select name="device" aria-label="Perangkat">
            <option value="">Semua perangkat</option>
            @foreach ($devices as $d)
                <option value="{{ $d->public_id }}" @selected($device?->is($d))>{{ $d->displayLabel() }}</option>
            @endforeach
        </select>
        <select name="kind" aria-label="Jenis">
            <option value="">Situs + koneksi</option>
            <option value="url" @selected($kind === 'url')>Situs (riwayat browser)</option>
            <option value="connection" @selected($kind === 'connection')>Koneksi (IP)</option>
        </select>
        <input type="search" name="q" value="{{ $search }}" placeholder="Cari domain atau IP…" maxlength="100" aria-label="Cari domain atau IP">
        <label>Dari <input type="date" name="from" value="{{ $range->inputFrom() }}" max="{{ $range->inputTo() }}"></label>
        <label>Sampai <input type="date" name="to" value="{{ $range->inputTo() }}"></label>
        <button type="submit" class="btn">Terapkan</button>
        @foreach (['24h' => '24 jam', '7d' => '7 hari', '30d' => '30 hari'] as $key => $label)
            <button type="submit" name="preset" value="{{ $key }}" class="btn btn-small">{{ $label }}</button>
        @endforeach
    </form>
    <p class="muted">Rentang: {{ $range->label() }} (waktu {{ config('netwatch.display_timezone') }}) · maksimal 90 hari</p>

    <div class="two-col">
        <section>
            <h2>Situs paling sering</h2>
            <table class="table">
                <thead><tr><th>Domain</th><th>Jumlah</th><th>Perangkat</th></tr></thead>
                <tbody>
                @forelse ($topHosts as $row)
                    <tr>
                        <td><a href="{{ route('browsing.index', ['q' => $row->host] + $query) }}">{{ $row->host }}</a></td>
                        <td>{{ $row->total }}</td><td>{{ $row->devices }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">Belum ada data pada rentang ini.</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
        <section>
            <h2>IP tujuan paling sering</h2>
            <table class="table">
                <thead><tr><th>IP</th><th>Nama</th><th>Jumlah</th><th>Perangkat</th></tr></thead>
                <tbody>
                @forelse ($topIps as $row)
                    <tr>
                        <td><a href="{{ route('browsing.index', ['q' => $row->ip] + $query) }}"><code>{{ $row->ip }}</code></a></td>
                        <td>{{ $row->name ?? '—' }}</td><td>{{ $row->total }}</td><td>{{ $row->devices }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">Belum ada data koneksi.</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
    </div>

    <h2>Rincian</h2>
    @if ($visits === null)
        <p class="muted">Pilih perangkat atau ketik kata pencarian untuk melihat rincian per kunjungan. Ekspor CSV tetap memuat seluruh rincian pada rentang ini.</p>
    @else
        <table class="table">
            <thead><tr><th>Waktu</th><th>Perangkat</th><th>Jenis</th><th>Domain / URL</th><th>IP</th><th>Proses / browser</th></tr></thead>
            <tbody>
            @forelse ($visits as $v)
                <tr>
                    <td>{{ $v->visited_at->toDisplay() }}</td>
                    <td><a href="{{ route('browsing.index', ['device' => $v->device->public_id] + $query) }}">{{ $v->device->displayLabel() }}</a></td>
                    <td><span class="badge">{{ $v->kind === 'url' ? 'Situs' : 'Koneksi' }}</span></td>
                    <td>{{ $v->url ?? $v->host ?? '—' }}</td>
                    <td>@if ($v->ip)<code>{{ $v->ip }}</code>@if ($v->port):{{ $v->port }}@endif @else — @endif</td>
                    <td class="muted">{{ $v->browser ?? $v->process ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Tidak ada catatan mentah yang cocok (data yang lebih lama dari {{ $rawDays }} hari hanya ada sebagai ringkasan di atas).</td></tr>
            @endforelse
            </tbody>
        </table>

        {{ $visits->links('pagination.pages') }}
    @endif

    <h2>Domain yang diabaikan</h2>
    <p class="muted">Domain latar belakang (update, telemetri, cek sertifikat) beserta subdomainnya tidak disimpan, supaya riwayat lebih bermakna dan database tidak penuh. Berlaku untuk data baru.</p>
    <form method="POST" action="{{ route('browsing.ignored.store') }}" class="filters">
        @csrf
        <input type="text" name="domain" placeholder="contoh: windowsupdate.com" maxlength="253" required aria-label="Domain">
        <input type="text" name="note" placeholder="Catatan (opsional)" maxlength="100" aria-label="Catatan">
        <button type="submit" class="btn">Tambah</button>
    </form>
    <table class="table">
        <thead><tr><th>Domain</th><th>Catatan</th><th></th></tr></thead>
        <tbody>
        @forelse ($ignored as $row)
            <tr>
                <td><code>{{ $row->domain }}</code></td>
                <td class="muted">{{ $row->note ?? '—' }}</td>
                <td>
                    <form method="POST" action="{{ route('browsing.ignored.destroy', $row) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-small">Hapus</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="3" class="muted">Belum ada domain yang diabaikan.</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
