@extends('layouts.app')

@section('title', 'Token Pendaftaran')

@section('content')
    <h1>Token pendaftaran</h1>
    <p class="muted">Token dipakai sekali saat memasang agent di komputer user. Token hanya ditampilkan satu kali setelah dibuat.</p>

    @if (session('new_token'))
        <div class="alert alert-ok" role="status">
            <strong>Token baru (salin sekarang, tidak akan ditampilkan lagi):</strong>
            <code class="token-box">{{ session('new_token') }}</code>
            <div class="muted">Windows (PowerShell Administrator): <code>.\install.ps1 -ServerUrl {{ url('/') }} -EnrollmentToken {{ session('new_token') }}</code></div>
            <div class="muted">Linux (root): <code>./install.sh --server {{ url('/') }} --token {{ session('new_token') }}</code></div>
        </div>
    @endif

    <form method="POST" action="{{ route('tokens.store') }}" class="filters">
        @csrf
        <input type="text" name="label" placeholder="Label (mis. Lab 1 batch Oktober)" required maxlength="100" aria-label="Label">
        <select name="device_group_id" aria-label="Grup tujuan">
            <option value="">Tanpa grup</option>
            @foreach ($groups as $group)
                <option value="{{ $group->id }}">{{ $group->name }}</option>
            @endforeach
        </select>
        <label class="inline">Maks. pemakaian <input type="number" name="max_uses" value="1" min="1" max="1000" required></label>
        <label class="inline">Berlaku (jam) <input type="number" name="ttl_hours" value="{{ $defaultTtlHours }}" min="1" max="720" required></label>
        <button type="submit" class="btn btn-primary">Buat token</button>
    </form>

    <table class="table">
        <thead><tr><th>Label</th><th>Grup</th><th>Dipakai</th><th>Kedaluwarsa</th><th>Dibuat oleh</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse ($tokens as $token)
            <tr>
                <td>{{ $token->label }}</td>
                <td>{{ $token->group?->name ?? '—' }}</td>
                <td>{{ $token->used_count }} / {{ $token->max_uses }}</td>
                <td>{{ $token->expires_at->toDisplay() }}</td>
                <td>{{ $token->creator?->name ?? '—' }}</td>
                <td>
                    @if ($token->revoked_at)
                        <span class="badge badge-off">Dicabut</span>
                    @elseif ($token->isUsable())
                        <span class="badge badge-ok">Aktif</span>
                    @else
                        <span class="badge badge-off">Habis/kedaluwarsa</span>
                    @endif
                </td>
                <td>
                    @if ($token->isUsable())
                        <form method="POST" action="{{ route('tokens.destroy', $token) }}" data-confirm="Cabut token ini?">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-small btn-danger">Cabut</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">Belum ada token.</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
