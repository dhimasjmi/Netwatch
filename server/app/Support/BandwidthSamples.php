<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Sumber data bandwidth untuk laporan. Rentang yang seluruhnya masih ada di sampel mentah memakai tabel mentah
 * (resolusi per heartbeat); rentang lebih lama menggabungkan ringkasan per jam (untuk bagian lama) dan sampel
 * mentah (dua jam terakhir yang belum dirangkum), tanpa menghitung dua kali.
 *
 * Hasilnya Query Builder ber-alias "s" dengan kolom device_interface_id, sampled_at, rx_bytes, tx_bytes, interval_seconds
 * dan filter waktunya sudah terpasang.
 */
final class BandwidthSamples
{
    /** Ringkasan per jam dipakai sampai batas ini dari sekarang; setelahnya sampel mentah. Rollup berjalan tiap jam, jadi 2 jam cukup aman. */
    private const SPLIT_LAG_HOURS = 2;

    public static function between(CarbonInterface $from, CarbonInterface $to, ?CarbonInterface $now = null): Builder
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $from = CarbonImmutable::instance($from)->utc();
        $to = CarbonImmutable::instance($to)->utc();

        $rawFloor = $now->startOfHour()->subDays((int) config('netwatch.retention.bandwidth_raw_days'));
        if ($from->gte($rawFloor)) {
            return DB::table('interface_samples as s')->whereBetween('s.sampled_at', [$from, $to]);
        }

        $split = $now->startOfHour()->subHours(self::SPLIT_LAG_HOURS);

        $hourly = DB::table('interface_samples_hourly')
            ->where('hour', '>=', $from)->where('hour', '<', $to->lt($split) ? $to : $split)
            ->selectRaw('device_interface_id, hour AS sampled_at, rx_bytes, tx_bytes, interval_seconds');

        $raw = DB::table('interface_samples')
            ->where('sampled_at', '>=', $from->gt($split) ? $from : $split)->where('sampled_at', '<=', $to)
            ->selectRaw('device_interface_id, sampled_at, rx_bytes, tx_bytes, interval_seconds');

        return DB::query()->fromSub($hourly->unionAll($raw), 's');
    }
}
