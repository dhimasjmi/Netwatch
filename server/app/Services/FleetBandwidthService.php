<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Support\BandwidthMath;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Bandwidth seluruh atau sebagian perangkat: ringkasan (KPI), tren gabungan, dan tabel per perangkat dengan pagination.
 * Hasil di-cache beberapa detik supaya banyak penonton dengan auto-refresh cepat tidak membebani database.
 */
final class FleetBandwidthService
{
    /** @var array<string, int> */
    public const WINDOWS = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800];
    public const PER_PAGE = [5, 10, 25, 50, 100];
    public const SORTS = ['name', 'ip', 'rx', 'tx', 'total', 'last_seen'];
    private const NOW_SECONDS = 300;

    /**
     * @param  array{window: string, group: int|null, q: string|null, sort: string, dir: string, page: int, per_page: int, devices: list<string>, only: bool}  $f
     * @return array<string, mixed>
     */
    public function report(array $f): array
    {
        $ttl = (int) config('netwatch.fleet_cache_seconds');

        return $ttl > 0 ? Cache::remember('fleet-bw:'.md5(serialize($f)), $ttl, fn (): array => $this->build($f)) : $this->build($f);
    }

    /**
     * @param  array{window: string, group: int|null, q: string|null, sort: string, dir: string, page: int, per_page: int, devices: list<string>, only: bool}  $f
     * @return array<string, mixed>
     */
    private function build(array $f): array
    {
        $seconds = self::WINDOWS[$f['window']];
        $to = CarbonImmutable::now();
        $from = $to->subSeconds($seconds);
        $bucket = BandwidthMath::bucketSeconds($seconds);
        $selected = $f['devices'] === [] ? [] : Device::query()->active()->whereIn('public_id', $f['devices'])->pluck('id')->all();

        $scopeIds = $this->base($f['group'])->when($selected !== [], fn (Builder $q) => $q->whereIn('devices.id', $selected))->select('devices.id');

        $trend = $this->trend($scopeIds, $from, $to, $bucket);
        $now = $this->nowRates($scopeIds, $to);

        $totalRx = array_sum(array_column($trend['raw'], 1));
        $totalTx = array_sum(array_column($trend['raw'], 2));

        $threshold = $to->subSeconds((int) config('netwatch.offline_after'));
        $fleet = $this->base($f['group']);
        $devicesTotal = (clone $fleet)->count();
        $devicesOnline = (clone $fleet)->where('last_seen_at', '>=', $threshold)->count();

        return [
            'generated_at' => $to->getTimestamp(),
            'window' => $f['window'],
            'from' => $from->getTimestamp(),
            'to' => $to->getTimestamp(),
            'bucket_seconds' => $bucket,
            'selected_count' => count($selected),
            'kpi' => [
                'devices_total' => $devicesTotal,
                'devices_online' => $devicesOnline,
                'devices_reporting' => $now['reporting'],
                'rx_bps_now' => $now['rx_bps'],
                'tx_bps_now' => $now['tx_bps'],
                'total_rx_bytes' => $totalRx,
                'total_tx_bytes' => $totalTx,
            ],
            'trend' => $trend['points'],
            'table' => $this->table($f, $from, $to, $seconds, $selected, $threshold),
        ];
    }

    /** @return Builder<Device> */
    private function base(?int $group): Builder
    {
        return Device::query()->active()->when($group !== null, fn (Builder $q) => $q->where('devices.device_group_id', $group));
    }

    /**
     * @param  Builder<Device>  $scopeIds
     * @return array{points: list<array{0: int, 1: int, 2: int}>, raw: list<array{0: int, 1: int, 2: int}>}
     */
    private function trend(Builder $scopeIds, CarbonImmutable $from, CarbonImmutable $to, int $bucket): array
    {
        // $bucket berasal dari daftar tetap (integer), aman disisipkan langsung.
        $rows = DB::table('interface_samples as s')
            ->join('device_interfaces as i', 'i.id', '=', 's.device_interface_id')
            ->whereIn('i.device_id', $scopeIds->toBase())
            ->whereBetween('s.sampled_at', [$from, $to])
            ->selectRaw("floor(extract(epoch from s.sampled_at) / {$bucket})::bigint * {$bucket} AS ts, sum(s.rx_bytes)::bigint AS rx, sum(s.tx_bytes)::bigint AS tx")
            ->groupBy('ts')->orderBy('ts')->get();

        $points = [];
        $raw = [];
        foreach ($rows as $row) {
            $ts = (int) $row->ts;
            // Bucket terakhir belum penuh: pembagi memakai waktu yang sudah berjalan agar tidak tampak rendah.
            $span = max(60, min($bucket, $to->getTimestamp() - $ts));
            $points[] = [$ts, (int) round(BandwidthMath::bitsPerSecond((int) $row->rx, $span)), (int) round(BandwidthMath::bitsPerSecond((int) $row->tx, $span))];
            $raw[] = [$ts, (int) $row->rx, (int) $row->tx];
        }

        return ['points' => $points, 'raw' => $raw];
    }

    /**
     * @param  Builder<Device>  $scopeIds
     * @return array{rx_bps: int, tx_bps: int, reporting: int}
     */
    private function nowRates(Builder $scopeIds, CarbonImmutable $to): array
    {
        $row = DB::table('interface_samples as s')
            ->join('device_interfaces as i', 'i.id', '=', 's.device_interface_id')
            ->whereIn('i.device_id', $scopeIds->toBase())
            ->whereBetween('s.sampled_at', [$to->subSeconds(self::NOW_SECONDS), $to])
            ->selectRaw('coalesce(sum(s.rx_bytes), 0)::bigint AS rx, coalesce(sum(s.tx_bytes), 0)::bigint AS tx, count(DISTINCT i.device_id) AS reporting')
            ->first();

        return [
            'rx_bps' => (int) round(BandwidthMath::bitsPerSecond((int) $row->rx, self::NOW_SECONDS)),
            'tx_bps' => (int) round(BandwidthMath::bitsPerSecond((int) $row->tx, self::NOW_SECONDS)),
            'reporting' => (int) $row->reporting,
        ];
    }

    /**
     * @param  array{window: string, group: int|null, q: string|null, sort: string, dir: string, page: int, per_page: int, devices: list<string>, only: bool}  $f
     * @param  list<int>  $selected
     * @return array<string, mixed>
     */
    private function table(array $f, CarbonImmutable $from, CarbonImmutable $to, int $seconds, array $selected, CarbonImmutable $threshold): array
    {
        $agg = DB::table('interface_samples as s')
            ->join('device_interfaces as i', 'i.id', '=', 's.device_interface_id')
            ->whereBetween('s.sampled_at', [$from, $to])
            ->groupBy('i.device_id')
            ->selectRaw('i.device_id, sum(s.rx_bytes)::bigint AS rx, sum(s.tx_bytes)::bigint AS tx');

        $term = $f['q'] !== null ? trim($f['q']) : '';
        $filtered = $this->base($f['group'])
            ->leftJoinSub($agg, 'a', 'a.device_id', '=', 'devices.id')
            ->when($f['only'] && $selected !== [], fn (Builder $q) => $q->whereIn('devices.id', $selected))
            ->when($term !== '', function (Builder $q) use ($term): void {
                $like = '%'.addcslashes(mb_strtolower($term), '\\%_').'%';
                $q->where(fn (Builder $w) => $w->whereRaw('lower(coalesce(devices.display_name, devices.hostname)) LIKE ?', [$like])->orWhereRaw('devices.ip_address::text LIKE ?', [$like]));
            });

        $maxTotal = (int) (clone $filtered)->toBase()->selectRaw('coalesce(max(coalesce(a.rx, 0) + coalesce(a.tx, 0)), 0) AS m')->value('m');

        $direction = $f['dir'] === 'asc' ? 'asc' : 'desc';
        $order = match ($f['sort']) {
            'name' => 'lower(coalesce(devices.display_name, devices.hostname))',
            'ip' => 'devices.ip_address::text',
            'rx' => 'coalesce(a.rx, 0)',
            'tx' => 'coalesce(a.tx, 0)',
            'last_seen' => 'devices.last_seen_at',
            default => 'coalesce(a.rx, 0) + coalesce(a.tx, 0)',
        };

        $page = $filtered
            ->selectRaw('devices.id, devices.public_id, devices.hostname, devices.display_name, devices.ip_address, devices.last_seen_at, coalesce(a.rx, 0) AS rx, coalesce(a.tx, 0) AS tx')
            ->orderByRaw("{$order} {$direction} NULLS LAST")->orderBy('devices.id')
            ->paginate($f['per_page'], ['*'], 'page', $f['page']);

        $rows = [];
        foreach ($page->items() as $device) {
            $rx = (int) $device->getAttribute('rx');
            $tx = (int) $device->getAttribute('tx');
            $rows[] = [
                'id' => $device->public_id,
                'name' => $device->displayLabel(),
                'ip' => $device->ip_address,
                'online' => $device->last_seen_at !== null && $device->last_seen_at->gte($threshold),
                'last_seen' => $device->last_seen_at?->getTimestamp(),
                'rx_bps' => (int) round(BandwidthMath::bitsPerSecond($rx, $seconds)),
                'tx_bps' => (int) round(BandwidthMath::bitsPerSecond($tx, $seconds)),
                'rx_bytes' => $rx,
                'tx_bytes' => $tx,
                'total_bytes' => $rx + $tx,
                'usage_pct' => $maxTotal > 0 ? (int) round(($rx + $tx) * 100 / $maxTotal) : 0,
                'url' => route('devices.bandwidth', $device),
            ];
        }

        return ['rows' => $rows, 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()];
    }
}
