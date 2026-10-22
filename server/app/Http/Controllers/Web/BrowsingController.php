<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\BrowsingFilterRequest;
use App\Models\BrowsingIgnoredHost;
use App\Models\Device;
use App\Services\AuditLogger;
use App\Services\BrowsingReportService;
use App\Support\Csv;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BrowsingController extends Controller
{
    private const PAGE_SIZE = 100;
    private const CSV_MAX_ROWS = 50_000;

    public function index(BrowsingFilterRequest $request, BrowsingReportService $reports, AuditLogger $audit): View
    {
        $range = $request->range();
        $device = $this->device($request);
        $kind = $request->input('kind');
        $search = $request->input('q');

        // Data ini sensitif: setiap pembukaan dicatat siapa yang melihat apa.
        $audit->record('browsing.viewed', $request->user(), $device, array_filter(['kind' => $kind, 'q' => $search, 'range' => $range->label()]));

        $scope = $reports->scope($range, $device, $kind, $search);
        $daily = $reports->dailyScope($range, $device, $kind, $search);

        // Rincian per kunjungan hanya dimuat bila sudah dipersempit (perangkat atau kata kunci): tanpa filter, tabelnya bisa jutaan baris.
        $showDetail = $device !== null || ($search !== null && trim((string) $search) !== '');

        return view('browsing.index', [
            'visits' => $showDetail ? (clone $scope)->with('device')->orderByDesc('visited_at')->orderByDesc('id')->paginate(self::PAGE_SIZE)->withQueryString() : null,
            'topHosts' => $reports->topHosts($scope, $daily),
            'topIps' => $reports->topIps($scope, $daily),
            'ignored' => BrowsingIgnoredHost::query()->orderBy('domain')->get(),
            'rawDays' => (int) config('netwatch.retention.browsing_days'),
            'dailyDays' => (int) config('netwatch.retention.browsing_daily_days'),
            'range' => $range,
            'device' => $device,
            'kind' => $kind,
            'search' => $search,
            'enabled' => (bool) config('netwatch.browsing.enabled'),
            'detail' => config('netwatch.browsing.detail'),
            'devices' => Device::query()->active()->orderBy('hostname')->get(['id', 'public_id', 'hostname', 'display_name']),
            'query' => array_filter($request->only(['from', 'to', 'preset', 'device', 'kind', 'q'])),
        ]);
    }

    public function csv(BrowsingFilterRequest $request, BrowsingReportService $reports, AuditLogger $audit): StreamedResponse
    {
        $range = $request->range();
        $device = $this->device($request);
        $timezone = (string) config('netwatch.display_timezone');

        $audit->record('browsing.exported', $request->user(), $device, ['range' => $range->label()]);

        $scope = $reports->scope($range, $device, $request->input('kind'), $request->input('q'));

        return response()->streamDownload(function () use ($scope, $timezone): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['waktu', 'perangkat', 'jenis', 'domain', 'ip', 'port', 'url', 'proses', 'browser']);

            $scope->with('device')->orderByDesc('visited_at')->orderByDesc('id')->limit(self::CSV_MAX_ROWS)->cursor()
                ->each(function ($v) use ($out, $timezone): void {
                    fputcsv($out, array_map(Csv::cell(...), [
                        $v->visited_at->copy()->timezone($timezone)->format('Y-m-d H:i:s'),
                        $v->device->displayLabel(),
                        $v->kind === 'url' ? 'situs' : 'koneksi',
                        (string) $v->host, (string) $v->ip, (string) $v->port, (string) $v->url, (string) $v->process, (string) $v->browser,
                    ]));
                });

            fclose($out);
        }, 'browsing.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function device(BrowsingFilterRequest $request): ?Device
    {
        return $request->filled('device') ? Device::query()->where('public_id', $request->input('device'))->first() : null;
    }
}
