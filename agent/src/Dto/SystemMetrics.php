<?php

declare(strict_types=1);

namespace NetWatch\Agent\Dto;

final class SystemMetrics
{
    public function __construct(
        public readonly float $cpuPercent,
        public readonly int $memoryUsedMb,
        public readonly int $memoryTotalMb,
        public readonly int $diskUsedGb,
        public readonly int $diskTotalGb,
        public readonly int $uptimeSeconds,
        public readonly ?string $loggedInUser,
    ) {
    }

    /** @return array<string, int|float|string|null> */
    public function toArray(): array
    {
        return [
            'cpu_percent' => round(max(0.0, min(100.0, $this->cpuPercent)), 2),
            'memory_used_mb' => max(0, $this->memoryUsedMb),
            'memory_total_mb' => max(0, $this->memoryTotalMb),
            'disk_used_gb' => max(0, $this->diskUsedGb),
            'disk_total_gb' => max(0, $this->diskTotalGb),
            'uptime_seconds' => max(0, $this->uptimeSeconds),
            'logged_in_user' => $this->loggedInUser === null ? null : mb_substr($this->loggedInUser, 0, 255),
        ];
    }
}
