<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Keputusan murni (tanpa database) untuk satu pasangan aturan-perangkat. Dipisahkan agar mudah diuji.
 *
 * Menyala: seluruh sampel dalam jendela durasi >= ambang DAN jendela benar-benar tercakup data
 * (perangkat yang baru online tidak langsung memicu alert). Pulih: semua sampel terbaru < ambang.
 */
final class AlertDecision
{
    public const FIRE = 'fire';
    public const RESOLVE = 'resolve';
    public const NONE = 'none';

    /**
     * @param  float|null  $lowestInWindow  nilai terendah di jendela durasi (null = tidak ada sampel)
     * @param  float|null  $recentMax  nilai tertinggi pada sampel terbaru (null = tidak ada sampel terbaru)
     */
    public static function decide(bool $firing, bool $windowCovered, ?float $lowestInWindow, ?float $recentMax, int $threshold): string
    {
        if (! $firing) {
            return $windowCovered && $lowestInWindow !== null && $lowestInWindow >= $threshold ? self::FIRE : self::NONE;
        }

        return $recentMax !== null && $recentMax < $threshold ? self::RESOLVE : self::NONE;
    }
}
