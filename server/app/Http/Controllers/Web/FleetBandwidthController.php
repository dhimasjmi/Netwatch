<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\FleetBandwidthRequest;
use App\Models\DeviceGroup;
use App\Services\FleetBandwidthService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/** Bandwidth seluruh atau beberapa perangkat sekaligus (halaman interaktif + endpoint JSON-nya). */
final class FleetBandwidthController extends Controller
{
    public function index(): View
    {
        return view('monitoring.bandwidth', [
            'groups' => DeviceGroup::query()->orderBy('name')->get(['id', 'name']),
            'windows' => array_keys(FleetBandwidthService::WINDOWS),
            'perPage' => FleetBandwidthService::PER_PAGE,
        ]);
    }

    public function data(FleetBandwidthRequest $request, FleetBandwidthService $service): JsonResponse
    {
        return response()->json($service->report($request->filters()))->header('Cache-Control', 'no-store');
    }
}
