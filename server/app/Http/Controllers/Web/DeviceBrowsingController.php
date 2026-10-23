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

/** Browsing satu perangkat (dari halaman perangkat). Datanya sama dengan Browsing Monitoring, hanya difilter ke perangkat ini. */
final class DeviceBrowsingController extends Controller
{
    private const AUDIT_EVERY_SECONDS = 600;

    public function index(Request $request, Device $device, AuditLogger $audit): View
    {
        $audit->record('browsing.viewed', $request->user(), $device, ['page' => 'device']);

        return view('devices.browsing', [
            'device' => $device,
            'windows' => array_keys(BrowsingDashboardService::WINDOWS),
            'perPage' => BrowsingDashboardService::PER_PAGE,
        ]);
    }

    public function data(BrowsingMonitorRequest $request, Device $device, BrowsingDashboardService $service, AuditLogger $audit): JsonResponse
    {
        // Perangkat ditentukan oleh alamat halaman, bukan parameter pencarian.
        $filters = ['device' => $device->public_id] + $request->filters();

        $key = 'audit-browsing-device:'.$request->user()->id.':'.$device->id;
        if ($filters['q'] !== null && Cache::add($key, true, self::AUDIT_EVERY_SECONDS)) {
            $audit->record('browsing.viewed', $request->user(), $device, array_filter(['kind' => $filters['kind'], 'q' => $filters['q'], 'window' => $filters['window']]));
        }

        return response()->json($service->report($filters))->header('Cache-Control', 'no-store');
    }
}
