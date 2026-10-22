<?php

declare(strict_types=1);

namespace App\Support;

/** Perhitungan bandwidth murni (tanpa database) agar mudah diuji. */
final class BandwidthMath
{
    /** Heartbeat yang terlambat lebih dari ini tidak dihitung sebagai satu sampel (rata-ratanya menyesatkan). */
    public const MAX_SAMPLE_GAP_SECONDS = 300;

    private const BUCKETS = [60, 300, 900, 3600, 10800, 21600, 86400];
    private const TARGET_POINTS = 300;

    /**
     * Selisih dua pembacaan counter kumulatif.
     *
     * @return array{rx: int, tx: int, seconds: int}|null null bila tidak ada sampel valid
     *         (counter reset karena reboot, jeda terlalu panjang, atau waktu mundur) — pemanggil cukup rebase.
     */
    public static function delta(
        int $prevRx,
        int $prevTx,
        int $prevTimestamp,
        int $rx,
        int $tx,
        int $timestamp,
    ): ?array {
        $seconds = $timestamp - $prevTimestamp;

        if ($seconds <= 0 || $seconds > self::MAX_SAMPLE_GAP_SECONDS) {
            return null;
        }

        if ($rx < $prevRx || $tx < $prevTx) {
            return null; // Counter turun = reboot atau adapter di-reset.
        }

        return ['rx' => $rx - $prevRx, 'tx' => $tx - $prevTx, 'seconds' => $seconds];
    }

    /** Ukuran bucket (detik) terkecil yang membuat jumlah titik grafik ≤ ~300. */
    public static function bucketSeconds(int $rangeSeconds): int
    {
        foreach (self::BUCKETS as $bucket) {
            if ($rangeSeconds / $bucket <= self::TARGET_POINTS) {
                return $bucket;
            }
        }

        return self::BUCKETS[array_key_last(self::BUCKETS)];
    }

    /** Rata-rata bit per detik dari total byte dalam durasi tertentu. */
    public static function bitsPerSecond(int $bytes, int $seconds): float
    {
        return $seconds > 0 ? ($bytes * 8) / $seconds : 0.0;
    }
}
