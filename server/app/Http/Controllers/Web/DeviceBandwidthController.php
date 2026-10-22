<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\UptimeRangeRequest;
use App\Models\Device;
use App\Services\BandwidthReportService;
use Illuminate\Http\JsonResponse;
use App\Support\Csv;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DeviceBandwidthController extends Controller
{
    public function show(UptimeRangeRequest $request, Device $device): View
    {
        abort_if($device->revoked_at !== null, 404);

        return view('devices.bandwidth', [
            'device' => $device,
            'range' => $request->range(),
            // Query string yang sama dipakai JS untuk auto-refresh, sehingga filter tetap berlaku.
            'dataUrl' => route('devices.bandwidth.data', ['device' => $device] + $request->only(['from', 'to', 'preset'])),
        ]);
    }

    public function data(UptimeRangeRequest $request, Device $device, BandwidthReportService $reports): JsonResponse
    {
        abort_if($device->revoked_at !== null, 404);

        return response()->json($reports->forDevice($device, $request->range()))
            ->header('Cache-Control', 'no-store');
    }

    public function csv(UptimeRangeRequest $request, Device $device, BandwidthReportService $reports): StreamedResponse
    {
        abort_if($device->revoked_at !== null, 404);

        $report = $reports->forDevice($device, $request->range());
        $timezone = (string) config('netwatch.display_timezone');

        return response()->streamDownload(function () use ($report, $timezone): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8 dengan benar
            fputcsv($out, ['interface', 'waktu', 'unduh_bps', 'unggah_bps']);

            foreach ($report['interfaces'] as $interface) {
                foreach ($interface['points'] as [$timestamp, $rx, $tx]) {
                    fputcsv($out, [
                        Csv::cell((string) $interface['name']),
                        \Carbon\CarbonImmutable::createFromTimestamp($timestamp, $timezone)->format('Y-m-d H:i:s'),
                        $rx,
                        $tx,
                    ]);
                }
            }

            fclose($out);
        }, 'bandwidth-'.$device->public_id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
