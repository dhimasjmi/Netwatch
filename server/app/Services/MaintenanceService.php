<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\User;

final class MaintenanceService
{
    public function __construct(private readonly AuditLogger $audit, private readonly EventRecorder $events)
    {
    }

    public function start(Device $device, int $hours, ?string $note, User $actor): void
    {
        $until = now()->addHours($hours);
        $device->forceFill(['maintenance_until' => $until, 'maintenance_note' => $note !== null && $note !== '' ? $note : null])->save();

        $this->audit->record('device.maintenance_started', $actor, $device, ['hours' => $hours]);
        $this->events->record('device.maintenance_started',
            'Mode maintenance dimulai sampai '.$until->toDisplay().' oleh '.$actor->name.'.', $device, $actor, ['hours' => $hours]);
    }

    public function end(Device $device, User $actor): void
    {
        if ($device->maintenance_until === null) {
            return;
        }

        $device->forceFill(['maintenance_until' => null, 'maintenance_note' => null])->save();

        $this->audit->record('device.maintenance_ended', $actor, $device);
        $this->events->record('device.maintenance_ended', 'Mode maintenance diakhiri oleh '.$actor->name.'.', $device, $actor);
    }
}
