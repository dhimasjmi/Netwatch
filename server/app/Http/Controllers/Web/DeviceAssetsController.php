<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceHardware;
use App\Models\DeviceSoftware;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Inventaris perangkat keras dan daftar program terpasang untuk satu perangkat. */
final class DeviceAssetsController extends Controller
{
    public function __invoke(Request $request, Device $device): View
    {
        abort_if($device->revoked_at !== null, 404);

        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        $software = DeviceSoftware::query()->where('device_id', $device->id)
            ->when($search !== '', function ($q) use ($search): void {
                $like = '%'.addcslashes(mb_strtolower($search), '\\%_').'%';
                $q->where(fn ($w) => $w->whereRaw('lower(name) LIKE ?', [$like])->orWhereRaw('lower(publisher) LIKE ?', [$like]));
            })
            ->orderBy('name')->paginate(100)->withQueryString();

        return view('devices.assets', [
            'device' => $device,
            'hardware' => DeviceHardware::query()->where('device_id', $device->id)->first(),
            'software' => $software,
            'softwareTotal' => DeviceSoftware::query()->where('device_id', $device->id)->count(),
            'search' => $search,
        ]);
    }
}
