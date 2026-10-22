<?php

declare(strict_types=1);

namespace App\Support;

/** Membuat atribut `points` untuk <polyline> SVG dari deret angka 0-100. */
final class Sparkline
{
    /** @param  list<int|float>  $values */
    public static function points(array $values, int $width = 240, int $height = 48): string
    {
        $count = count($values);

        if ($count < 2) {
            return '';
        }

        $step = $width / ($count - 1);
        $points = [];

        foreach (array_values($values) as $index => $value) {
            $clamped = max(0.0, min(100.0, (float) $value));
            $points[] = sprintf('%.1f,%.1f', $index * $step, $height - ($clamped / 100 * $height));
        }

        return implode(' ', $points);
    }
}
