<?php

declare(strict_types=1);

namespace App\Support;

/** Menghitung ketersediaan dari interval status. Murni (tanpa database) agar mudah diuji. */
final class UptimeCalculator
{
    /** @param  iterable<StatusInterval>  $intervals */
    public static function summarize(iterable $intervals, int $from, int $to): UptimeSummary
    {
        $covered = 0;
        $down = 0;
        $outages = 0;
        $longest = 0;

        foreach (self::clipped($intervals, $from, $to) as [$isDown, $start, $end]) {
            $length = $end - $start;
            $covered += $length;

            if ($isDown) {
                $down += $length;
                $outages++;
                $longest = max($longest, $length);
            }
        }

        return new UptimeSummary($covered, $down, $outages, $longest);
    }

    /**
     * Segmen yang dipotong ke rentang, terurut waktu, untuk menggambar timeline.
     *
     * @param  iterable<StatusInterval>  $intervals
     * @return list<array{down: bool, start: int, end: int}>
     */
    public static function segments(iterable $intervals, int $from, int $to): array
    {
        $segments = [];

        foreach (self::clipped($intervals, $from, $to) as [$isDown, $start, $end]) {
            $segments[] = ['down' => $isDown, 'start' => $start, 'end' => $end];
        }

        usort($segments, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $segments;
    }

    /**
     * @param  iterable<StatusInterval>  $intervals
     * @return \Generator<int, array{0: bool, 1: int, 2: int}>
     */
    private static function clipped(iterable $intervals, int $from, int $to): \Generator
    {
        if ($to <= $from) {
            return;
        }

        foreach ($intervals as $interval) {
            $start = max($interval->start, $from);
            $end = min($interval->end ?? $to, $to);

            if ($end > $start) {
                yield [$interval->down, $start, $end];
            }
        }
    }
}
