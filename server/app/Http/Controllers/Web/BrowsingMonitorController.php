<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\BrowsingMonitorRequest;
use App\Models\Device;
use App\Services\AuditLogger;
use App\Services\BrowsingDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/** Browsing Monitor interaktif (hanya administrator; data sensitif, aksesnya diaudit). */
final class BrowsingMonitorController extends Controller
{
    private const AUDIT_EVERY_SECONDS = 600;

    public function index(Request $request, AuditLogger $audit): View
    {
        $audit->record('browsing.viewed', $request->user(), null, ['page' => 'monitor']);

        return view('monitoring.browsing', [
            'devices' => Device::query()->active()->orderBy('hostname')->get(['id', 'public_id', 'hostname', 'display_name']),
            'windows' => array_keys(BrowsingDashboardService::WINDOWS),
            'perPage' => BrowsingDashboardService::PER_PAGE,
            'detail' => config('netwatch.browsing.detail'),
        ]);
    }

    public function data(BrowsingMonitorRequest $request, BrowsingDashboardService $service, AuditLogger $audit): JsonResponse
    {
        $filters = $request->filters();

        // Auto-refresh memanggil endpoint ini terus; audit dicatat sekali per kombinasi filter per 10 menit agar log tidak banjir.
        $key = 'audit-browsing:'.$request->user()->id.':'.md5(serialize([$filters['device'], $filters['kind'], $filters['q']]));
        if (($filters['device'] !== null || $filters['q'] !== null) && Cache::add($key, true, self::AUDIT_EVERY_SECONDS)) {
            $audit->record('browsing.viewed', $request->user(), null, array_filter(['device' => $filters['device'], 'kind' => $filters['kind'], 'q' => $filters['q'], 'window' => $filters['window']]));
        }

        return response()->json($service->report($filters))->header('Cache-Control', 'no-store');
    }
}
