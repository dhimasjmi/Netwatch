<?php

declare(strict_types=1);

namespace App\Support;

/** Mengubah deret angka (skala bebas, mis. bps) menjadi titik polyline SVG; bisa berbagi skala antar garis. */
final class SeriesPoints
{
    /**
     * @param  list<int|float>  $values
     * @param  float|null  $max  skala bersama beberapa garis; bawaan = nilai terbesar deret ini
     */
    public static function points(array $values, int $width, int $height, ?float $max = null): string
    {
        $count = count($values);
        if ($count === 0) {
            return '';
        }
        $max = $max ?? max(array_map('floatval', $values));
        $max = $max > 0 ? $max : 1.0;
        $step = $count > 1 ? $width / ($count - 1) : 0.0;

        $points = [];
        foreach (array_values($values) as $i => $value) {
            $x = $count > 1 ? $i * $step : $width / 2;
            $y = $height - (max(0.0, (float) $value) / $max) * $height;
            $points[] = round($x, 1).','.round($y, 1);
        }

        return implode(' ', $points);
    }
}
