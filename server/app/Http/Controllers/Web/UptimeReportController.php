<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\UptimeRangeRequest;
use App\Models\DeviceGroup;
use App\Services\UptimeReportService;
use App\Support\Csv;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class UptimeReportController extends Controller
{
    public function index(UptimeRangeRequest $request, UptimeReportService $reports): View
    {
        $range = $request->range();
        $groupId = $request->integer('group') ?: null;

        return view('reports.uptime', [
            'range' => $range,
            'rows' => $reports->fleet($range, $groupId),
            'groups' => DeviceGroup::query()->orderBy('name')->get(),
            'groupId' => $groupId,
            'query' => $request->only(['from', 'to', 'preset', 'group']),
        ]);
    }

    public function csv(UptimeRangeRequest $request, UptimeReportService $reports): StreamedResponse
    {
        $range = $request->range();
        $rows = $reports->fleet($range, $request->integer('group') ?: null);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8 dengan benar
            fputcsv($out, ['hostname', 'grup', 'ketersediaan_persen', 'jumlah_gangguan', 'downtime_detik', 'gangguan_terlama_detik', 'detik_tercatat']);

            foreach ($rows as ['device' => $device, 'summary' => $summary]) {
                fputcsv($out, [
                    Csv::cell($device->displayLabel()),
                    Csv::cell((string) $device->group?->name),
                    $summary->availabilityPercent() ?? '',
                    $summary->outageCount,
                    $summary->downSeconds,
                    $summary->longestOutageSeconds,
                    $summary->coveredSeconds,
                ]);
            }

            fclose($out);
        }, 'uptime-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
