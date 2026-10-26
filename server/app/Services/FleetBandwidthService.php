<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Support\BandwidthMath;
use App\Support\BandwidthSamples;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

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
        if ($ttl > 0 && in_array($f['window'], ['24h', '7d'], true)) {
            $ttl = max($ttl, 30); // rentang panjang berat dihitung ulang dan jarang berubah
        }

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
        $rows = BandwidthSamples::between($from, $to)
            ->join('device_interfaces as i', 'i.id', '=', 's.device_interface_id')
            ->whereIn('i.device_id', $scopeIds->toBase())
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
        $row = BandwidthSamples::between($to->subSeconds(self::NOW_SECONDS), $to)
            ->join('device_interfaces as i', 'i.id', '=', 's.device_interface_id')
            ->whereIn('i.device_id', $scopeIds->toBase())
            ->selectRaw('coalesce(sum(s.rx_bytes), 0)::bigint AS rx, coalesce(sum(s.tx_bytes), 0)::bigint AS tx, count(DISTINCT i.device_id) AS reporting')
            ->first();

        return [
            'rx_bps' => (int) round(BandwidthMath::bitsPerSecond((int) $row->rx, self::NOW_SECONDS)),
            'tx_bps' => (int) round(BandwidthMath::bitsPerSecond((int) $row->tx, self::NOW_SECONDS)),
            'reporting' => (int) $row->reporting,
        ];
    }

    /**
     * Total per perangkat dihitung dengan SATU pemindaian sampel (hasilnya satu baris per perangkat); penyaringan,
     * pengurutan, dan halaman dikerjakan di memori. Jauh lebih murah daripada tiga kali agregasi pada tabel besar.
     *
     * @param  array{window: string, group: int|null, q: string|null, sort: string, dir: string, page: int, per_page: int, devices: list<string>, only: bool}  $f
     * @param  list<int>  $selected
     * @return array<string, mixed>
     */
    private function table(array $f, CarbonImmutable $from, CarbonImmutable $to, int $seconds, array $selected, CarbonImmutable $threshold): array
    {
        $totals = BandwidthSamples::between($from, $to)
            ->join('device_interfaces as i', 'i.id', '=', 's.device_interface_id')
            ->groupBy('i.device_id')
            ->selectRaw('i.device_id, sum(s.rx_bytes)::bigint AS rx, sum(s.tx_bytes)::bigint AS tx')
            ->get()->keyBy('device_id');

        $term = $f['q'] !== null ? mb_strtolower(trim($f['q'])) : '';
        $devices = $this->base($f['group'])
            ->when($f['only'] && $selected !== [], fn (Builder $q) => $q->whereIn('devices.id', $selected))
            ->get(['devices.id', 'devices.public_id', 'devices.hostname', 'devices.display_name', 'devices.ip_address', 'devices.last_seen_at'])
            ->filter(fn (Device $d): bool => $term === '' || str_contains(mb_strtolower($d->displayLabel()), $term) || str_contains(mb_strtolower((string) $d->ip_address), $term))
            ->map(function (Device $d) use ($totals): array {
                $t = $totals->get($d->id);

                return ['device' => $d, 'rx' => (int) ($t->rx ?? 0), 'tx' => (int) ($t->tx ?? 0)];
            })->values();

        $maxTotal = (int) $devices->max(fn (array $r): int => $r['rx'] + $r['tx']);
        $sign = $f['dir'] === 'asc' ? 1 : -1;
        $key = fn (array $r): int|string|float => match ($f['sort']) {
            'name' => mb_strtolower($r['device']->displayLabel()),
            'ip' => (string) $r['device']->ip_address,
            'rx' => $r['rx'],
            'tx' => $r['tx'],
            'last_seen' => $r['device']->last_seen_at?->getTimestamp() ?? 0,
            default => $r['rx'] + $r['tx'],
        };

        $sorted = $devices->all();
        usort($sorted, function (array $a, array $b) use ($key, $sign, $f): int {
            if ($f['sort'] === 'last_seen') { // belum pernah terlihat selalu di akhir
                $na = $a['device']->last_seen_at === null;
                $nb = $b['device']->last_seen_at === null;
                if ($na !== $nb) {
                    return $na ? 1 : -1;
                }
            }

            return ($sign * ($key($a) <=> $key($b))) ?: ($a['device']->id <=> $b['device']->id);
        });

        $total = count($sorted);
        $lastPage = max(1, (int) ceil($total / $f['per_page']));
        $page = $f['page']; // halaman di luar batas = kosong; tampilan akan melompat ke halaman terakhir
        $rows = [];
        foreach (array_slice($sorted, ($page - 1) * $f['per_page'], $f['per_page']) as $r) {
            $device = $r['device'];
            $rows[] = [
                'id' => $device->public_id,
                'name' => $device->displayLabel(),
                'ip' => $device->ip_address,
                'online' => $device->last_seen_at !== null && $device->last_seen_at->gte($threshold),
                'last_seen' => $device->last_seen_at?->getTimestamp(),
                'rx_bps' => (int) round(BandwidthMath::bitsPerSecond($r['rx'], $seconds)),
                'tx_bps' => (int) round(BandwidthMath::bitsPerSecond($r['tx'], $seconds)),
                'rx_bytes' => $r['rx'],
                'tx_bytes' => $r['tx'],
                'total_bytes' => $r['rx'] + $r['tx'],
                'usage_pct' => $maxTotal > 0 ? (int) round(($r['rx'] + $r['tx']) * 100 / $maxTotal) : 0,
                'url' => route('devices.bandwidth', $device),
            ];
        }

        return ['rows' => $rows, 'page' => $page, 'per_page' => $f['per_page'], 'total' => $total, 'last_page' => $lastPage];
    }
}
