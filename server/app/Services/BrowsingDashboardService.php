<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BrowsingDaily;
use App\Models\BrowsingVisit;
use App\Models\Device;
use App\Support\BandwidthMath;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data halaman Browsing Monitor: KPI, tren aktivitas, situs teratas, dan tabel aktivitas terbaru berpagination.
 * Memakai data mentah (rincian) dan ringkasan harian (data lama) lewat BrowsingReportService yang sudah ada.
 */
final class BrowsingDashboardService
{
    /** @var array<string, int> */
    public const WINDOWS = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800, '30d' => 2592000];
    public const PER_PAGE = [10, 25, 50, 100];
    private const TOP_SITES = 8;
    private const COUNT_CAP = 100_000;

    public function __construct(private readonly BrowsingReportService $reports)
    {
    }

    /**
     * @param  array{window: string, device: string|null, kind: string|null, q: string|null, page: int, per_page: int}  $f
     * @return array<string, mixed>
     */
    public function report(array $f): array
    {
        $ttl = (int) config('netwatch.fleet_cache_seconds');

        return $ttl > 0 ? Cache::remember('browsing-dash:'.md5(serialize($f)), $ttl, fn (): array => $this->build($f)) : $this->build($f);
    }

    /**
     * @param  array{window: string, device: string|null, kind: string|null, q: string|null, page: int, per_page: int}  $f
     * @return array<string, mixed>
     */
    private function build(array $f): array
    {
        $seconds = self::WINDOWS[$f['window']];
        $timezone = (string) config('netwatch.display_timezone');
        $range = DateRange::lastSeconds($seconds, $timezone);
        $device = $f['device'] !== null ? Device::query()->active()->where('public_id', $f['device'])->first() : null;

        $scope = $this->reports->scope($range, $device, $f['kind'], $f['q']);
        $daily = $this->reports->dailyScope($range, $device, $f['kind'], $f['q']);
        // Ringkasan harian hanya relevan bila jendela mencakup hari penuh yang sudah dirangkum (data lebih tua dari retensi mentah).
        $useDaily = $seconds > (int) config('netwatch.retention.browsing_days') * 86400 - 86400;

        $grouped = $this->grouped($scope, $daily, $useDaily);
        $kpi = (clone $grouped)->selectRaw("coalesce(sum(n), 0) AS total, count(DISTINCT device_id) AS devices, count(DISTINCT host) FILTER (WHERE host <> '') AS sites")->first();
        $top = DB::query()->fromSub(clone $grouped, 'g')->where('host', '<>', '')
            ->selectRaw('host, sum(n) AS total, count(DISTINCT device_id) AS devices')->groupBy('host')->orderByDesc('total')->orderBy('host')->limit(self::TOP_SITES)->get();
        $hostTotal = (int) DB::query()->fromSub(clone $grouped, 'g')->where('host', '<>', '')->sum('n');

        return [
            'generated_at' => now()->getTimestamp(),
            'window' => $f['window'],
            'from' => $range->from->getTimestamp(),
            'to' => $range->to->getTimestamp(),
            'kpi' => [
                'devices_total' => Device::query()->active()->count(),
                'devices_active' => (int) $kpi->devices,
                'total' => (int) $kpi->total,
                'unique_sites' => (int) $kpi->sites,
                'top_site' => $top->first()->host ?? null,
            ],
            'trend' => $this->trend($scope, $daily, $seconds, $range, $useDaily, $timezone),
            'top_sites' => $top->map(fn ($row): array => [
                'host' => (string) $row->host,
                'total' => (int) $row->total,
                'devices' => (int) $row->devices,
                'share_pct' => $hostTotal > 0 ? round((int) $row->total * 100 / $hostTotal, 1) : 0.0,
            ])->all(),
            'table' => $this->table($scope, $f),
            'raw_days' => (int) config('netwatch.retention.browsing_days'),
        ];
    }

    /**
     * Gabungan data mentah dan ringkasan harian per (perangkat, situs).
     *
     * @param  Builder<BrowsingVisit>  $scope
     * @param  Builder<BrowsingDaily>  $daily
     */
    private function grouped(Builder $scope, Builder $daily, bool $useDaily): \Illuminate\Database\Query\Builder
    {
        $raw = (clone $scope)->selectRaw("device_id, coalesce(host, '') AS host, count(*) AS n")->groupByRaw("device_id, coalesce(host, '')")->toBase();

        $union = $raw;
        if ($useDaily) {
            $old = (clone $daily)->selectRaw("device_id, coalesce(host, '') AS host, sum(visits) AS n")->groupByRaw("device_id, coalesce(host, '')")->toBase();
            $union = $raw->unionAll($old);
        }

        return DB::query()->fromSub($union, 'u');
    }

    /**
     * @param  Builder<BrowsingVisit>  $scope
     * @param  Builder<BrowsingDaily>  $daily
     * @return array{bucket_seconds: int, points: list<array{0: int, 1: int, 2: int}>}
     */
    private function trend(Builder $scope, Builder $daily, int $seconds, DateRange $range, bool $useDaily, string $timezone): array
    {
        if (! $useDaily) {
            $bucket = BandwidthMath::bucketSeconds($seconds);
            $rows = (clone $scope)->toBase()
                ->selectRaw("floor(extract(epoch from visited_at) / {$bucket})::bigint * {$bucket} AS ts, count(*) FILTER (WHERE kind = 'url') AS urls, count(*) FILTER (WHERE kind = 'connection') AS conns")
                ->groupBy('ts')->orderBy('ts')->get();

            return ['bucket_seconds' => $bucket, 'points' => $rows->map(fn ($r): array => [(int) $r->ts, (int) $r->urls, (int) $r->conns])->all()];
        }

        // Rentang panjang: per hari (zona waktu tampilan), data mentah + ringkasan harian digabung.
        $tz = DB::getPdo()->quote($timezone);
        $raw = (clone $scope)->toBase()
            ->selectRaw("(visited_at AT TIME ZONE {$tz})::date AS day, count(*) FILTER (WHERE kind = 'url') AS urls, count(*) FILTER (WHERE kind = 'connection') AS conns")->groupBy('day');
        $old = (clone $daily)->toBase()
            ->selectRaw("day, coalesce(sum(visits) FILTER (WHERE kind = 'url'), 0) AS urls, coalesce(sum(visits) FILTER (WHERE kind = 'connection'), 0) AS conns")->groupBy('day');

        $rows = DB::query()->fromSub($raw->unionAll($old), 'd')
            ->selectRaw('day, sum(urls) AS urls, sum(conns) AS conns')->groupBy('day')->orderBy('day')->get();

        $points = $rows->map(fn ($r): array => [
            CarbonImmutable::createFromFormat('!Y-m-d', (string) $r->day, $timezone)->getTimestamp(), (int) $r->urls, (int) $r->conns,
        ])->all();

        return ['bucket_seconds' => 86400, 'points' => $points];
    }

    /**
     * @param  Builder<BrowsingVisit>  $scope
     * @param  array{window: string, device: string|null, kind: string|null, q: string|null, page: int, per_page: int}  $f
     * @return array<string, mixed>
     */
    private function table(Builder $scope, array $f): array
    {
        // Hitung dibatasi agar rentang besar tanpa filter tidak memindai jutaan baris; di atas batas ditampilkan "100.000+".
        $counted = (int) DB::query()->fromSub((clone $scope)->select('id')->limit(self::COUNT_CAP + 1)->toBase(), 'c')->count();
        $capped = $counted > self::COUNT_CAP;
        $total = min($counted, self::COUNT_CAP);

        $rows = (clone $scope)->with('device')->orderByDesc('visited_at')->orderByDesc('id')->forPage($f['page'], $f['per_page'])->get()
            ->map(fn (BrowsingVisit $v): array => [
                'id' => $v->id,
                'time' => $v->visited_at->getTimestamp(),
                'device' => $v->device->displayLabel(),
                'device_id' => $v->device->public_id,
                'device_ip' => $v->device->ip_address,
                'kind' => $v->kind,
                'host' => $v->host,
                'url' => $v->url,
                'ip' => $v->ip,
                'port' => $v->port,
                'app' => $v->browser ?? $v->process,
            ])->all();

        return [
            'rows' => $rows, 'page' => $f['page'], 'per_page' => $f['per_page'],
            'total' => $total, 'total_capped' => $capped, 'last_page' => max(1, (int) ceil($total / $f['per_page'])),
        ];
    }
}
