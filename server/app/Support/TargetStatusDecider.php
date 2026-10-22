<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\TargetStatus;

/**
 * Menentukan status target setelah satu pengecekan. Murni agar mudah diuji.
 * Satu kegagalan tidak langsung "Offline" (paket ping bisa hilang sesekali); status berubah setelah
 * $failThreshold kegagalan beruntun. Respons lebih lambat dari $warnMs ditandai "Lambat".
 */
final class TargetStatusDecider
{
    /** @return array{status: TargetStatus, failures: int} */
    public static function next(TargetStatus $current, int $failures, bool $ok, ?float $latencyMs, float $warnMs, int $failThreshold): array
    {
        if ($ok) {
            return [
                'status' => $latencyMs !== null && $latencyMs > $warnMs ? TargetStatus::Warning : TargetStatus::Online,
                'failures' => 0,
            ];
        }

        $failures++;

        return [
            'status' => $failures >= max(1, $failThreshold) ? TargetStatus::Offline : $current,
            'failures' => $failures,
        ];
    }

    /** Persentase cek berhasil, dibulatkan satu desimal; null bila belum ada data. */
    public static function availability(int $total, int $ok): ?float
    {
        return $total === 0 ? null : round($ok * 100 / $total, 1);
    }
}
