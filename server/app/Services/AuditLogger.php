<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\User;

final class AuditLogger
{
    /**
     * Catat aksi sensitif. Jangan pernah memasukkan token atau kata sandi ke $metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?User $user = null,
        ?Device $device = null,
        array $metadata = [],
        ?string $ip = null,
    ): void {
        AuditLog::query()->create([
            'user_id' => $user?->id,
            'device_id' => $device?->id,
            'action' => $action,
            'ip_address' => $ip ?? request()->ip(),
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ]);
    }
}
