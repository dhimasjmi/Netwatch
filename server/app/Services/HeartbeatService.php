<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceHeartbeat;
use Carbon\CarbonInterface;
use Throwable;

final class HeartbeatService
{
    public function __construct(private readonly StatusTracker $status, private readonly BandwidthRecorder $bandwidth)
    {
    }

    /**
     * @param  array{cpu_percent: int|float, memory_used_mb: int, memory_total_mb: int, disk_used_gb: int, disk_total_gb: int, uptime_seconds: int, logged_in_user?: string|null}  $metrics
     * @param  list<array{name: string, rx_bytes: int, tx_bytes: int, speed_bps?: int|null}>  $interfaces
     */
    public function record(Device $device, array $metrics, ?string $ip, ?string $agentVersion, array $interfaces = []): void
    {
        $now = now();
        $previousSeen = $device->last_seen_at;

        DeviceHeartbeat::query()->create([
            'device_id' => $device->id,
            'cpu_percent' => round((float) $metrics['cpu_percent'], 2),
            'memory_used_mb' => $metrics['memory_used_mb'],
            'memory_total_mb' => $metrics['memory_total_mb'],
            'disk_used_gb' => $metrics['disk_used_gb'],
            'disk_total_gb' => $metrics['disk_total_gb'],
            'uptime_seconds' => $metrics['uptime_seconds'],
            'logged_in_user' => $metrics['logged_in_user'] ?? null,
            'recorded_at' => $now,
        ]);

        $device->forceFill([
            'latest_metrics' => [...$metrics, 'recorded_at' => $now->toIso8601String()],
            'last_seen_at' => $now,
            'ip_address' => $ip ?? $device->ip_address,
            'agent_version' => $agentVersion ?? $device->agent_version,
        ])->save();

        $this->recordBandwidthSafely($device, $interfaces, $now);

        $silenceLimit = $now->copy()->subSeconds((int) config('netwatch.offline_after'));

        if ($previousSeen !== null && $previousSeen->lt($silenceLimit)) {
            $this->status->recordGapAndMarkOnline($device, $previousSeen, $now);

            return;
        }

        $this->status->markOnline($device, $now);
    }

    /** Kegagalan mencatat bandwidth tidak boleh membuat heartbeat (dan status online) ikut gagal. */
    private function recordBandwidthSafely(Device $device, array $interfaces, CarbonInterface $now): void
    {
        try {
            $this->bandwidth->record($device, $interfaces, $now);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
