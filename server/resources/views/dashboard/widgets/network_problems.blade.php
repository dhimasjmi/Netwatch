@if ($d === null)
    <p class="muted">Data belum bisa dimuat.</p>
@else
    @forelse ($d['problems'] as $target)
        <div class="list-row">
            <span><span class="status-dot status-{{ $target->status->value }}" aria-hidden="true"></span> <a href="{{ route('network.index', ['q' => $target->address]) }}">{{ $target->name }}</a> <span class="muted">{{ $target->address }}</span></span>
            <span class="badge {{ $target->status->badgeClass() }}">{{ $target->status === \App\Enums\TargetStatus::Warning && $target->latency_ms !== null ? round($target->latency_ms).' ms' : $target->status->label() }}</span>
        </div>
    @empty
        <p class="muted">Semua perangkat jaringan merespons dengan baik.</p>
    @endforelse
@endif
