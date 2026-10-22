<?php

declare(strict_types=1);

namespace App\Support;

final class Duration
{
    /** Format ringkas dua satuan terbesar, mis. "1 h 3 j", "5 j 20 m", "45 dtk". */
    public static function human(int $seconds): string
    {
        $seconds = max(0, $seconds);

        if ($seconds < 60) {
            return $seconds.' dtk';
        }

        $parts = [];

        foreach ([['h', intdiv($seconds, 86400)], ['j', intdiv($seconds % 86400, 3600)], ['m', intdiv($seconds % 3600, 60)]] as [$unit, $value]) {
            if ($value > 0) {
                $parts[] = $value.' '.$unit;
            }
        }

        return implode(' ', array_slice($parts, 0, 2));
    }
}
