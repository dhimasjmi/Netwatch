<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\ProcessSnapshot;

final class ProcessSnapshotService
{
    /**
     * @param  list<array{pid: int, name: string, cpu_seconds?: int|float|null, memory_mb?: int|float|null, user?: string|null}>  $processes
     */
    public function store(Device $device, array $processes): ProcessSnapshot
    {
        $limit = (int) config('netwatch.max_processes_per_snapshot');

        $rows = array_map(
            static fn (array $p): array => [
                'pid' => (int) $p['pid'],
                'name' => (string) $p['name'],
                'cpu_seconds' => isset($p['cpu_seconds']) ? round((float) $p['cpu_seconds'], 1) : null,
                'memory_mb' => isset($p['memory_mb']) ? round((float) $p['memory_mb'], 1) : null,
                'user' => $p['user'] ?? null,
            ],
            array_slice($processes, 0, $limit),
        );

        $snapshot = ProcessSnapshot::query()->create([
            'device_id' => $device->id,
            'captured_at' => now(),
            'process_count' => count($rows),
            'processes' => $rows,
        ]);

        $this->trimOldSnapshots($device);

        return $snapshot;
    }

    private function trimOldSnapshots(Device $device): void
    {
        $keepIds = ProcessSnapshot::query()
            ->where('device_id', $device->id)
            ->orderByDesc('captured_at')
            ->limit((int) config('netwatch.retention.snapshots_keep'))
            ->pluck('id');

        ProcessSnapshot::query()
            ->where('device_id', $device->id)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }
}
