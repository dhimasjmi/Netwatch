<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Merangkum sampel bandwidth mentah menjadi ringkasan per jam, lalu menghapus sampel mentah yang sudah lewat masa simpan.
 * Aman dijalankan berulang (upsert dari jam-jam utuh) dan tidak pernah menghapus sampel yang belum dirangkum.
 */
final class BandwidthRollup
{
    private const CHUNK_HOURS = 6;
    private const MAX_HOURS_PER_RUN = 24 * 14;
    private const DELETE_CHUNK = 10_000;

    /** @return array{rolled_hours: int, deleted: int} */
    public function run(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $end = $now->startOfHour(); // hanya jam yang sudah utuh

        $last = DB::table('interface_samples_hourly')->max('hour');
        $oldestRaw = DB::table('interface_samples')->min('sampled_at');
        $start = $last !== null ? CarbonImmutable::parse($last, 'UTC') : ($oldestRaw !== null ? CarbonImmutable::parse($oldestRaw, 'UTC')->startOfHour() : null);

        $rolledTo = $last !== null ? CarbonImmutable::parse($last, 'UTC') : $start;
        $hours = 0;
        if ($start !== null) {
            for ($cursor = $start; $cursor->lt($end) && $hours < self::MAX_HOURS_PER_RUN; $cursor = $next) {
                $next = $cursor->addHours(self::CHUNK_HOURS)->min($end);
                $this->rollup($cursor, $next);
                $hours += (int) ($cursor->diffInHours($next));
                $rolledTo = $next;
            }
        }

        // Sampel mentah hanya boleh dihapus bila jamnya sudah masuk ringkasan.
        $keepFrom = $now->startOfHour()->subDays((int) config('netwatch.retention.bandwidth_raw_days'));
        $safe = $rolledTo !== null ? $keepFrom->min($rolledTo) : $keepFrom->min($oldestRaw !== null ? CarbonImmutable::parse($oldestRaw, 'UTC')->startOfHour() : $keepFrom);

        return ['rolled_hours' => $hours, 'deleted' => $this->deleteRawBefore($safe)];
    }

    /** Hapus ringkasan per jam yang lebih tua dari masa simpan jangka panjang. */
    public function pruneHourly(CarbonImmutable $now): int
    {
        $cutoff = $now->subDays((int) config('netwatch.retention.bandwidth_days'));
        $total = 0;
        do {
            $deleted = DB::table('interface_samples_hourly')->whereIn('id', fn ($q) => $q->from('interface_samples_hourly')->select('id')->where('hour', '<', $cutoff)->limit(self::DELETE_CHUNK))->delete();
            $total += $deleted;
        } while ($deleted === self::DELETE_CHUNK);

        return $total;
    }

    private function rollup(CarbonImmutable $from, CarbonImmutable $to): void
    {
        // Batas jam dihitung di UTC agar sama di semua zona waktu server/sesi.
        DB::statement(<<<'SQL'
            INSERT INTO interface_samples_hourly (device_interface_id, hour, rx_bytes, tx_bytes, interval_seconds, samples)
            SELECT device_interface_id, date_trunc('hour', sampled_at AT TIME ZONE 'UTC') AT TIME ZONE 'UTC' AS hour,
                   sum(rx_bytes), sum(tx_bytes), sum(interval_seconds), count(*)
            FROM interface_samples
            WHERE sampled_at >= ? AND sampled_at < ?
            GROUP BY device_interface_id, 2
            ON CONFLICT (device_interface_id, hour) DO UPDATE SET
                rx_bytes = EXCLUDED.rx_bytes, tx_bytes = EXCLUDED.tx_bytes,
                interval_seconds = EXCLUDED.interval_seconds, samples = EXCLUDED.samples
            SQL, [$from, $to]);
    }

    private function deleteRawBefore(CarbonImmutable $before): int
    {
        $total = 0;
        do {
            $deleted = DB::table('interface_samples')->whereIn('id', fn ($q) => $q->from('interface_samples')->select('id')->where('sampled_at', '<', $before)->limit(self::DELETE_CHUNK))->delete();
            $total += $deleted;
        } while ($deleted === self::DELETE_CHUNK);

        return $total;
    }
}
