<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Menyusun titik grafik garis SVG dari deret waktu persentase (0-100). Murni, tanpa database, agar mudah diuji.
 */
final class TrendChart
{
    /**
     * Pecah menjadi segmen berkelanjutan; selisih waktu antar-titik lebih besar dari $maxGap memutus garis (perangkat offline).
     *
     * @param  array<int, float>  $series  timestamp Unix => nilai persen
     * @return list<string> tiap segmen berupa "x,y x,y ..." untuk atribut points <polyline>
     */
    public static function segments(array $series, int $start, int $end, int $width, int $height, int $maxGap): array
    {
        $span = max(1, $end - $start);
        $segments = [];
        $current = [];
        $previous = null;
        ksort($series);

        foreach ($series as $timestamp => $value) {
            if ($timestamp < $start || $timestamp > $end) {
                continue;
            }

            if ($previous !== null && $timestamp - $previous > $maxGap) {
                $segments[] = $current;
                $current = [];
            }

            $x = round(($timestamp - $start) / $span * $width, 1);
            $y = round($height - max(0.0, min(100.0, $value)) / 100 * $height, 1);
            $current[] = "{$x},{$y}";
            $previous = $timestamp;
        }

        if ($current !== []) {
            $segments[] = $current;
        }

        return array_map(static fn (array $points): string => implode(' ', count($points) === 1 ? [$points[0], $points[0]] : $points), $segments);
    }

    /**
     * @param  array<int, float>  $series
     * @return array{min: float, avg: float, max: float, last: float}|null
     */
    public static function summary(array $series): ?array
    {
        if ($series === []) {
            return null;
        }

        ksort($series);

        return [
            'min' => round(min($series), 1),
            'avg' => round(array_sum($series) / count($series), 1),
            'max' => round(max($series), 1),
            'last' => round((float) end($series), 1),
        ];
    }
}
