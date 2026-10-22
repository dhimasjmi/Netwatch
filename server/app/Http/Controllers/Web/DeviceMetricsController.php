<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceHeartbeat;
use App\Support\TrendChart;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Grafik tren CPU, RAM, dan disk satu perangkat dari riwayat heartbeat (rata-rata per interval). */
final class DeviceMetricsController extends Controller
{
    private const RANGES = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800];
    private const TARGET_POINTS = 120;
    private const WIDTH = 720;
    private const HEIGHT = 160;

    public function __invoke(Request $request, Device $device): View
    {
        $key = array_key_exists((string) $request->query('range'), self::RANGES) ? (string) $request->query('range') : '6h';
        $seconds = self::RANGES[$key];
        $end = now()->getTimestamp();
        $start = $end - $seconds;
        $bucket = max(30, (int) (round($seconds / self::TARGET_POINTS / 30) * 30)); // kelipatan 30 detik (interval heartbeat)

        $rows = DeviceHeartbeat::query()
            ->where('device_id', $device->id)
            ->where('recorded_at', '>=', now()->subSeconds($seconds))
            // $bucket adalah integer hasil hitung di atas, aman disisipkan langsung.
            ->selectRaw("(floor(extract(epoch from recorded_at) / {$bucket}) * {$bucket})::bigint AS ts, avg(cpu_percent) AS cpu, ".
                'avg(memory_used_mb * 100.0 / NULLIF(memory_total_mb, 0)) AS memory, avg(disk_used_gb * 100.0 / NULLIF(disk_total_gb, 0)) AS disk')
            ->groupBy('ts')->orderBy('ts')->get();

        $series = ['cpu' => [], 'memory' => [], 'disk' => []];
        foreach ($rows as $row) {
            foreach (['cpu', 'memory', 'disk'] as $metric) {
                if ($row->{$metric} !== null) {
                    $series[$metric][(int) $row->ts] = (float) $row->{$metric};
                }
            }
        }

        $charts = [];
        foreach (['cpu' => 'CPU', 'memory' => 'Memori (RAM)', 'disk' => 'Disk'] as $metric => $label) {
            $charts[] = [
                'label' => $label,
                'segments' => TrendChart::segments($series[$metric], $start, $end, self::WIDTH, self::HEIGHT, $bucket * 4),
                'summary' => TrendChart::summary($series[$metric]),
            ];
        }

        return view('devices.metrics', [
            'device' => $device,
            'charts' => $charts,
            'ranges' => array_keys(self::RANGES),
            'range' => $key,
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'startLabel' => \Carbon\CarbonImmutable::createFromTimestamp($start)->setTimezone((string) config('netwatch.display_timezone'))->format('d M H:i'),
            'endLabel' => \Carbon\CarbonImmutable::createFromTimestamp($end)->setTimezone((string) config('netwatch.display_timezone'))->format('d M H:i'),
        ]);
    }
}
