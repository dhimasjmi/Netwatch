<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representasi ringan status perangkat untuk polling dashboard.
 *
 * @property Device $resource
 */
final class DeviceLiveResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $device = $this->resource;
        $metrics = $device->latest_metrics ?? [];

        $memoryTotal = (int) ($metrics['memory_total_mb'] ?? 0);
        $diskTotal = (int) ($metrics['disk_total_gb'] ?? 0);

        return [
            'id' => $device->public_id,
            'online' => $device->isOnline(),
            'last_seen' => $device->last_seen_at?->diffForHumans(),
            'cpu_percent' => isset($metrics['cpu_percent']) ? (float) $metrics['cpu_percent'] : null,
            'memory_percent' => $memoryTotal > 0 ? round(((int) $metrics['memory_used_mb']) / $memoryTotal * 100, 1) : null,
            'memory_used_mb' => $metrics['memory_used_mb'] ?? null,
            'memory_total_mb' => $memoryTotal ?: null,
            'disk_percent' => $diskTotal > 0 ? round(((int) $metrics['disk_used_gb']) / $diskTotal * 100, 1) : null,
            'disk_used_gb' => $metrics['disk_used_gb'] ?? null,
            'disk_total_gb' => $diskTotal ?: null,
            'uptime_seconds' => $metrics['uptime_seconds'] ?? null,
            'logged_in_user' => $metrics['logged_in_user'] ?? null,
        ];
    }
}
