@extends('layouts.app')

@section('title', 'Tiket '.$ticket->number())

@section('main-attrs')data-page="ticket"@endsection

@section('content')
    <div class="page-head">
        <h1>{{ $ticket->number() }} — {{ $ticket->subject }}</h1>
        <div class="actions"><a class="btn" href="{{ route('tickets.index') }}">Kembali ke daftar</a></div>
    </div>

    @if (session('feedback_url'))
        <div class="alert" role="status">
            <strong>Kode penilaian (hanya tampil sekali):</strong> <code class="code-box">{{ session('feedback_code') }}</code><br>
            Tautan: <a href="{{ session('feedback_url') }}" target="_blank" rel="noopener">{{ session('feedback_url') }}</a>
            @foreach ((array) session('feedback_notes') as $note)<div class="muted">{{ $note }}</div>@endforeach
        </div>
    @endif

    <section class="card">
        <dl class="details">
            <dt>Status</dt><dd><span class="badge {{ $ticket->status->value === 'resolved' ? 'badge-ok' : '' }}">{{ $ticket->status->label() }}</span></dd>
            <dt>Pemohon</dt><dd>{{ $ticket->requester_name }} @if ($ticket->requester_email)({{ $ticket->requester_email }})@endif</dd>
            <dt>Perangkat</dt><dd>@if ($ticket->device)<a href="{{ route('devices.show', $ticket->device) }}">{{ $ticket->device->displayLabel() }}</a>@else — @endif</dd>
            <dt>Jenis</dt><dd>{{ $ticket->category->label() }}</dd>
            <dt>Dibuat</dt><dd>{{ $ticket->created_at->toDisplay() }} ({{ $ticket->source === 'admin' ? 'dibuatkan admin' : 'form pengguna' }})</dd>
            @if ($ticket->resolved_at)<dt>Selesai</dt><dd>{{ $ticket->resolved_at->toDisplay() }}</dd>@endif
        </dl>
        <h2>Penjelasan</h2>
        <p class="pre-line">{{ $ticket->description }}</p>
    </section>

    @if ($ticket->status->value !== 'resolved')
        <form method="POST" action="{{ route('tickets.update', $ticket) }}" class="filters">
            @csrf @method('PATCH')
            <select name="status" aria-label="Status">
                @foreach ($statuses as $s)<option value="{{ $s->value }}" @selected($ticket->status === $s)>{{ $s->label() }}</option>@endforeach
            </select>
            <select name="priority" aria-label="Prioritas">
                @foreach ($priorities as $p)<option value="{{ $p->value }}" @selected($ticket->priority === $p)>{{ $p->label() }}</option>@endforeach
            </select>
            <select name="assigned_to" aria-label="Penanggung jawab">
                <option value="">Belum ditugaskan</option>
                @foreach ($staff as $u)<option value="{{ $u->id }}" @selected($ticket->assigned_to === $u->id)>{{ $u->name }}</option>@endforeach
            </select>
            <button type="submit" class="btn">Simpan</button>
        </form>
    @endif

    <h2>Catatan</h2>
    @forelse ($ticket->comments as $c)
        <div class="card">
            <div class="muted">{{ $c->author?->name ?? 'Sistem' }} · {{ $c->created_at->toDisplay() }} @if ($c->is_internal)<span class="badge badge-pending">Internal</span>@endif</div>
            <p class="pre-line">{{ $c->body }}</p>
        </div>
    @empty
        <p class="muted">Belum ada catatan.</p>
    @endforelse

    <form method="POST" action="{{ route('tickets.comment', $ticket) }}" class="stack">
        @csrf
        <label>Tambah catatan <textarea name="body" rows="3" maxlength="2000" required></textarea></label>
        <label class="inline"><input type="checkbox" name="internal" value="1" checked> Catatan internal (tidak dikirim ke pemohon)</label>
        <button type="submit" class="btn">Simpan catatan</button>
    </form>

    @if ($ticket->status->value !== 'resolved')
        <h2>Selesaikan tiket</h2>
        <form method="POST" action="{{ route('tickets.resolve', $ticket) }}" class="stack">
            @csrf
            <label>Catatan penyelesaian (dikirim ke pemohon) <textarea name="note" rows="3" maxlength="1000"></textarea></label>
            <label class="inline"><input type="checkbox" name="send_email" value="1" @checked($emailAvailable) @disabled(! $emailAvailable)> Kirim tautan penilaian lewat email @unless ($emailAvailable)<span class="muted">(pemohon tanpa email atau MAIL_* belum diatur)</span>@endunless</label>
            <label class="inline"><input type="checkbox" name="send_popup" value="1" @checked($ticket->device !== null) @disabled($ticket->device === null)> Tampilkan popup di PC pemohon @if ($ticket->device === null)<span class="muted">(tiket tidak terkait perangkat)</span>@endif</label>
            <button type="submit" class="btn btn-primary">Tandai selesai</button>
        </form>
    @else
        <h2>Penilaian</h2>
        @if ($ticket->feedback?->submitted_at)
            <p><strong>{{ $ticket->feedback->rating }}/5</strong> — {{ $ticket->feedback->comment ?? 'tanpa komentar' }} <span class="muted">({{ $ticket->feedback->submitted_at->toDisplay() }})</span></p>
        @else
            <p class="muted">
                @if ($ticket->feedback)
                    Belum dinilai (kode berlaku sampai {{ $ticket->feedback->expires_at->toDisplay() }}, dikirim via {{ $ticket->feedback->sent_via ?? 'belum dikirim' }}).
                @else
                    Belum dinilai.
                @endif
            </p>
            <form method="POST" action="{{ route('tickets.feedback-code', $ticket) }}" class="stack">
                @csrf
                <label class="inline"><input type="checkbox" name="send_email" value="1" @checked($emailAvailable) @disabled(! $emailAvailable)> Kirim ulang lewat email</label>
                <label class="inline"><input type="checkbox" name="send_popup" value="1" @checked($ticket->device !== null) @disabled($ticket->device === null)> Kirim popup di PC</label>
                <button type="submit" class="btn">Buat kode penilaian baru</button>
            </form>
        @endif
    @endif
@endsection
