<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\StartMaintenanceRequest;
use App\Models\Device;
use App\Services\MaintenanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class DeviceMaintenanceController extends Controller
{
    public function store(StartMaintenanceRequest $request, Device $device, MaintenanceService $maintenance): RedirectResponse
    {
        abort_if($device->revoked_at !== null, 404);

        $maintenance->start($device, (int) $request->validated('hours'), $request->validated('note'), $request->user());

        return redirect()->route('devices.show', $device)->with('status', 'Mode maintenance aktif. Notifikasi offline untuk perangkat ini ditahan.');
    }

    public function destroy(Request $request, Device $device, MaintenanceService $maintenance): RedirectResponse
    {
        abort_if($device->revoked_at !== null, 404);

        $maintenance->end($device, $request->user());

        return redirect()->route('devices.show', $device)->with('status', 'Mode maintenance diakhiri.');
    }
}
