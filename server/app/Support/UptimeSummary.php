<?php

declare(strict_types=1);

namespace App\Support;

final class UptimeSummary
{
    public function __construct(
        /** Detik yang punya data riwayat dalam rentang (bukan selalu panjang rentang penuh). */
        public readonly int $coveredSeconds,
        public readonly int $downSeconds,
        public readonly int $outageCount,
        public readonly int $longestOutageSeconds,
    ) {
    }

    /** Null bila tidak ada data riwayat pada rentang itu. */
    public function availabilityPercent(): ?float
    {
        if ($this->coveredSeconds <= 0) {
            return null;
        }

        return round(($this->coveredSeconds - $this->downSeconds) / $this->coveredSeconds * 100, 3);
    }

    public function upSeconds(): int
    {
        return max(0, $this->coveredSeconds - $this->downSeconds);
    }

    /** Rata-rata lama satu gangguan (MTTR). */
    public function mttrSeconds(): ?int
    {
        return $this->outageCount > 0 ? intdiv($this->downSeconds, $this->outageCount) : null;
    }
}
