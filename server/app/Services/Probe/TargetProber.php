<?php

declare(strict_types=1);

namespace App\Services\Probe;

use App\Models\MonitorTarget;

/** Pengecek ketersediaan target. Dipisahkan sebagai antarmuka agar tes tidak perlu jaringan sungguhan. */
interface TargetProber
{
    /**
     * @param  list<MonitorTarget>  $targets
     * @return array<int, ProbeResult> hasil per id target
     */
    public function probe(array $targets): array;
}
