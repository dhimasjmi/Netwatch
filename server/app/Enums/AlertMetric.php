<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertMetric: string
{
    case Cpu = 'cpu';
    case Memory = 'memory';
    case Disk = 'disk';

    public function label(): string
    {
        return match ($this) {
            self::Cpu => 'CPU',
            self::Memory => 'Memori (RAM)',
            self::Disk => 'Disk',
        };
    }

    /**
     * Ekspresi SQL persen pemakaian pada tabel device_heartbeats. Tetap (tidak berasal dari input), aman disisipkan ke query.
     */
    public function sqlPercent(): string
    {
        return match ($this) {
            self::Cpu => 'cpu_percent',
            self::Memory => '(memory_used_mb * 100.0 / NULLIF(memory_total_mb, 0))',
            self::Disk => '(disk_used_gb * 100.0 / NULLIF(disk_total_gb, 0))',
        };
    }
}
