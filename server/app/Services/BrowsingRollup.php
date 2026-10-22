<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BrowsingVisit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Merangkum data browsing mentah yang sudah melewati masa retensi menjadi baris harian, lalu menghapus data mentahnya.
 * Per potongan, perangkuman dan penghapusan berada dalam satu transaksi sehingga tidak ada hitungan ganda atau data hilang.
 */
final class BrowsingRollup
{
    private const CHUNK = 50_000;

    /** @return array{rolled: int, deleted: int} jumlah baris mentah yang dirangkum dan dihapus */
    public function run(CarbonInterface $cutoff): array
    {
        $timezone = (string) config('netwatch.display_timezone');
        $total = 0;

        do {
            $maxId = BrowsingVisit::query()->where('visited_at', '<', $cutoff)->orderBy('id')->limit(self::CHUNK)->pluck('id')->last();

            if ($maxId === null) {
                break;
            }

            $deleted = DB::transaction(function () use ($cutoff, $maxId, $timezone): int {
                DB::statement(
                    'INSERT INTO browsing_daily (device_id, day, kind, host, ip, visits, first_at, last_at) '.
                    "SELECT device_id, (visited_at AT TIME ZONE ?)::date, kind, COALESCE(host, ''), ".
                    "CASE WHEN COALESCE(host, '') = '' THEN COALESCE(ip, '') ELSE '' END, count(*), min(visited_at), max(visited_at) ".
                    'FROM browsing_visits WHERE visited_at < ? AND id <= ? GROUP BY 1, 2, 3, 4, 5 '.
                    'ON CONFLICT (device_id, day, kind, host, ip) DO UPDATE SET visits = browsing_daily.visits + EXCLUDED.visits, '.
                    'first_at = LEAST(browsing_daily.first_at, EXCLUDED.first_at), last_at = GREATEST(browsing_daily.last_at, EXCLUDED.last_at)',
                    [$timezone, $cutoff, $maxId],
                );

                return BrowsingVisit::query()->where('visited_at', '<', $cutoff)->where('id', '<=', $maxId)->delete();
            });

            $total += $deleted;
        } while (true);

        return ['rolled' => $total, 'deleted' => $total];
    }
}
