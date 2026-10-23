@forelse ($d as $device)
    <div class="list-row">
        <a href="{{ route('devices.show', $device) }}">{{ $device->displayLabel() }}</a>
        <span class="muted">{{ $device->last_seen_at?->diffForHumans() ?? 'belum pernah terhubung' }}</span>
    </div>
@empty
    <p class="muted">Semua perangkat online.</p>
@endforelse
