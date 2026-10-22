<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\UptimeRangeRequest;
use App\Models\Device;
use App\Services\UptimeReportService;
use Illuminate\View\View;

final class DeviceUptimeController extends Controller
{
    public function __invoke(UptimeRangeRequest $request, Device $device, UptimeReportService $reports): View
    {
        abort_if($device->revoked_at !== null, 404);

        $range = $request->range();

        return view('devices.uptime', [
            'device' => $device,
            'range' => $range,
            ...$reports->forDevice($device, $range),
        ]);
    }
}
