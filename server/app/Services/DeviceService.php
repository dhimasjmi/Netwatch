<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\User;

final class DeviceService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StatusTracker $status,
        private readonly EventRecorder $events,
    ) {
    }

    /** @param  array{display_name?: string|null, device_group_id?: int|null}  $attributes */
    public function update(Device $device, array $attributes, User $actor): void
    {
        $device->fill($attributes)->save();

        $this->audit->record('device.updated', $actor, $device, array_keys($attributes));
    }

    /** Cabut akses perangkat: token agent langsung tidak berlaku, riwayat tetap tersimpan. */
    public function revoke(Device $device, User $actor): void
    {
        $device->forceFill(['revoked_at' => now()])->save();
        $this->status->close($device, now());

        $this->audit->record('device.revoked', $actor, $device, ['hostname' => $device->hostname]);
        $this->events->record('device.revoked', "Akses perangkat \"{$device->hostname}\" dicabut oleh {$actor->name}.", $device, $actor);
    }
}
