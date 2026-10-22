<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Menyusun batang mini riwayat ping (seperti monitor ping): tinggi batang = latensi, merah penuh = gagal.
 * Murni; hasilnya dipakai view untuk menggambar SVG (atribut SVG, bukan style inline, agar lolos CSP).
 */
final class LatencyBars
{
    public const BAR_WIDTH = 4;
    public const GAP = 1;
    public const HEIGHT = 32;

    /**
     * @param  list<array{ok: bool, latency: float|null}>  $checks urut dari terlama ke terbaru
     * @return list<array{x: int, y: int, height: int, class: string}>
     */
    public static function build(array $checks, float $warnMs): array
    {
        $scale = max($warnMs * 2, 1.0); // batang penuh = dua kali batas "lambat"
        $bars = [];

        foreach ($checks as $i => $check) {
            if (! $check['ok']) {
                $height = self::HEIGHT;
                $class = 'bar-fail';
            } else {
                $latency = $check['latency'] ?? 0.0;
                $height = max(3, (int) round(min($latency, $scale) / $scale * self::HEIGHT));
                $class = $latency > $warnMs ? 'bar-slow' : 'bar-ok';
            }
            $bars[] = ['x' => $i * (self::BAR_WIDTH + self::GAP), 'y' => self::HEIGHT - $height, 'height' => $height, 'class' => $class];
        }

        return $bars;
    }

    public static function width(int $count): int
    {
        return max(0, $count * (self::BAR_WIDTH + self::GAP) - self::GAP);
    }
}
