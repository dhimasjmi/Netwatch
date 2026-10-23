@forelse ($d as $command)
    <div class="list-row">
        <span>
            {{ $command->type->label() }} →
            <a href="{{ route('devices.show', $command->device) }}">{{ $command->device->displayLabel() }}</a>
        </span>
        <span class="badge badge-{{ $command->status->value }}">{{ $command->status->label() }}</span>
    </div>
@empty
    <p class="muted">Belum ada perintah. Buka halaman perangkat untuk mengirim perintah.</p>
@endforelse
