<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BrowsingDaily;
use App\Models\BrowsingVisit;
use App\Models\Device;
use App\Support\DateRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BrowsingReportService
{
    public const TOP_LIMIT = 15;

    /** @return Builder<BrowsingVisit> */
    public function scope(DateRange $range, ?Device $device, ?string $kind, ?string $search): Builder
    {
        $term = $search !== null ? trim($search) : '';

        return BrowsingVisit::query()
            ->whereBetween('visited_at', [$range->from, $range->to])
            ->when($device !== null, fn (Builder $q) => $q->where('device_id', $device->id))
            ->when($kind !== null && $kind !== '', fn (Builder $q) => $q->where('kind', $kind))
            ->when($term !== '', function (Builder $q) use ($term): void {
                // Karakter khusus LIKE dinetralkan agar pencarian "%" tidak memindai semuanya.
                $like = '%'.addcslashes(mb_strtolower($term), '\\%_').'%';
                $q->where(fn (Builder $w) => $w->whereRaw('lower(host) LIKE ?', [$like])->orWhereRaw('ip LIKE ?', [$like]));
            });
    }

    /**
     * Ringkasan harian (data lama yang sudah dirangkum) untuk rentang dan filter yang sama. Tidak bertumpang tindih dengan
     * data mentah karena baris mentah dihapus saat dirangkum.
     *
     * @return Builder<BrowsingDaily>
     */
    public function dailyScope(DateRange $range, ?Device $device, ?string $kind, ?string $search): Builder
    {
        $term = $search !== null ? trim($search) : '';
        $timezone = (string) config('netwatch.display_timezone');

        return BrowsingDaily::query()
            ->whereBetween('day', [$range->from->setTimezone($timezone)->toDateString(), $range->to->setTimezone($timezone)->toDateString()])
            ->when($device !== null, fn (Builder $q) => $q->where('device_id', $device->id))
            ->when($kind !== null && $kind !== '', fn (Builder $q) => $q->where('kind', $kind))
            ->when($term !== '', function (Builder $q) use ($term): void {
                $like = '%'.addcslashes(mb_strtolower($term), '\\%_').'%';
                $q->where(fn (Builder $w) => $w->whereRaw('lower(host) LIKE ?', [$like])->orWhereRaw('ip LIKE ?', [$like]));
            });
    }

    /**
     * Situs paling sering muncul (data mentah + ringkasan harian). Untuk kind=connection angka berarti "kemunculan koneksi".
     *
     * @param  Builder<BrowsingVisit>  $scope
     * @param  Builder<BrowsingDaily>  $daily
     * @return Collection<int, object{host: string, total: int, devices: int, last_at: string}>
     */
    public function topHosts(Builder $scope, Builder $daily): Collection
    {
        $raw = (clone $scope)->whereNotNull('host')
            ->selectRaw('device_id, host, count(*) AS n, max(visited_at) AS last_at')->groupBy('device_id', 'host')->toBase();
        $old = (clone $daily)->where('host', '<>', '')
            ->selectRaw('device_id, host, sum(visits) AS n, max(last_at) AS last_at')->groupBy('device_id', 'host')->toBase();

        return DB::query()->fromSub($raw->unionAll($old), 'u')
            ->selectRaw('host, sum(n) AS total, count(DISTINCT device_id) AS devices, max(last_at) AS last_at')
            ->groupBy('host')->orderByDesc('total')->orderBy('host')->limit(self::TOP_LIMIT)->get();
    }

    /**
     * IP tujuan paling sering muncul (hanya dari koneksi), dengan satu nama domain bila diketahui.
     * Data lama hanya menyimpan IP untuk koneksi yang tidak punya nama domain.
     *
     * @param  Builder<BrowsingVisit>  $scope
     * @param  Builder<BrowsingDaily>  $daily
     * @return Collection<int, object{ip: string, name: string|null, total: int, devices: int}>
     */
    public function topIps(Builder $scope, Builder $daily): Collection
    {
        $raw = (clone $scope)->where('kind', 'connection')->whereNotNull('ip')
            ->selectRaw('device_id, ip, max(host) AS name, count(*) AS n')->groupBy('device_id', 'ip')->toBase();
        $old = (clone $daily)->where('kind', 'connection')->where('ip', '<>', '')
            ->selectRaw('device_id, ip, NULL AS name, sum(visits) AS n')->groupBy('device_id', 'ip')->toBase();

        return DB::query()->fromSub($raw->unionAll($old), 'u')
            ->selectRaw('ip, max(name) AS name, sum(n) AS total, count(DISTINCT device_id) AS devices')
            ->groupBy('ip')->orderByDesc('total')->orderBy('ip')->limit(self::TOP_LIMIT)->get();
    }
}
