<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceSoftware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Inventaris seluruh perangkat: ringkasan perangkat keras dan pencarian "PC mana yang memasang program X". */
final class AssetController extends Controller
{
    public function index(Request $request): View
    {
        $tab = $request->query('tab') === 'software' ? 'software' : 'hardware';
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $like = '%'.addcslashes(mb_strtolower($search), '\\%_').'%';

        $hardware = null;
        $software = null;

        if ($tab === 'hardware') {
            $hardware = Device::query()->active()->with('hardware')
                ->when($search !== '', fn ($q) => $q->where(function ($w) use ($like): void {
                    $w->whereRaw('lower(coalesce(display_name, hostname)) LIKE ?', [$like])
                        ->orWhereHas('hardware', fn ($h) => $h->whereRaw('lower(coalesce(manufacturer, \'\') || \' \' || coalesce(model, \'\') || \' \' || coalesce(cpu, \'\') || \' \' || coalesce(serial, \'\')) LIKE ?', [$like]));
                }))
                ->orderByRaw('lower(coalesce(display_name, hostname))')->paginate(50)->withQueryString();
        } else {
            // Satu baris per nama program: berapa perangkat yang memasangnya dan versi apa saja.
            $software = DeviceSoftware::query()
                ->whereIn('device_id', Device::query()->active()->select('id'))
                ->when($search !== '', fn ($q) => $q->whereRaw('lower(name) LIKE ?', [$like]))
                ->select('name', DB::raw('count(DISTINCT device_id) AS devices'), DB::raw("string_agg(DISTINCT NULLIF(version, ''), ', ') AS versions"))
                ->groupBy('name')->orderByDesc('devices')->orderBy('name')->paginate(100)->withQueryString();
        }

        return view('assets.index', ['tab' => $tab, 'search' => $search, 'hardware' => $hardware, 'software' => $software]);
    }

    /** Daftar perangkat yang memasang satu program (halaman detail dari pencarian). */
    public function software(Request $request): View
    {
        $name = mb_substr((string) $request->query('name', ''), 0, 300);
        abort_if($name === '', 404);

        $rows = DeviceSoftware::query()->where('name', $name)
            ->whereIn('device_id', Device::query()->active()->select('id'))
            ->with('device')->orderBy('device_id')->limit(500)->get();

        return view('assets.software', ['name' => $name, 'rows' => $rows]);
    }
}
