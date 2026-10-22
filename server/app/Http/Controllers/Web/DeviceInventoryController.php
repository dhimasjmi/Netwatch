<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DevicePort;
use App\Models\DeviceService;
use App\Support\PortCatalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DeviceInventoryController extends Controller
{
    public function __invoke(Request $request, Device $device): View
    {
        abort_if($device->revoked_at !== null, 404);

        $tab = $request->query('tab') === 'services' ? 'services' : 'ports';
        $search = trim((string) $request->query('q', ''));
        $filter = (string) $request->query('filter', '');

        $ports = $this->ports($device, $search, $filter);
        $services = $this->services($device, $search, $filter);

        return view('devices.inventory', [
            'device' => $device,
            'tab' => $tab,
            'search' => $search,
            'filter' => $filter,
            'ports' => $ports['rows'],
            'portStats' => $ports['stats'],
            'services' => $services['rows'],
            'serviceStats' => $services['stats'],
        ]);
    }

    /** @return array{rows: list<array<string, mixed>>, stats: array{total: int, exposed: int, attention: int}} */
    private function ports(Device $device, string $search, string $filter): array
    {
        // Satu baris per protokol+port (alamat 0.0.0.0 dan :: digabung agar tidak tampak ganda).
        $grouped = [];
        foreach (DevicePort::query()->where('device_id', $device->id)->orderBy('port')->get() as $p) {
            $key = $p->protocol.'|'.$p->port;
            $row = $grouped[$key] ??= ['protocol' => $p->protocol, 'port' => $p->port, 'process' => $p->process, 'exposed' => false,
                'addresses' => [], 'first_seen_at' => $p->first_seen_at];
            $row['exposed'] = $row['exposed'] || $p->exposed;
            $row['addresses'][] = $p->address;
            $row['process'] ??= $p->process;
            $row['first_seen_at'] = $p->first_seen_at->lt($row['first_seen_at']) ? $p->first_seen_at : $row['first_seen_at'];
            $grouped[$key] = $row;
        }

        $all = array_map(static function (array $row): array {
            $row['name'] = PortCatalog::name($row['port']);
            $row['level'] = PortCatalog::level($row['port'], $row['exposed']);
            $row['hint'] = $row['exposed'] ? PortCatalog::hint($row['port']) : null;
            $row['is_new'] = $row['first_seen_at']->gt(now()->subDay());

            return $row;
        }, array_values($grouped));

        $stats = [
            'total' => count($all),
            'exposed' => count(array_filter($all, static fn (array $r): bool => $r['exposed'])),
            'attention' => count(array_filter($all, static fn (array $r): bool => $r['level'] !== PortCatalog::NORMAL)),
        ];

        $needle = mb_strtolower($search);
        $rows = array_values(array_filter($all, static fn (array $r): bool => match (true) {
            $filter === 'exposed' && ! $r['exposed'] => false,
            $filter === 'attention' && $r['level'] === PortCatalog::NORMAL => false,
            $needle !== '' => str_contains((string) $r['port'], $needle) || str_contains(mb_strtolower((string) $r['process']), $needle) || str_contains(mb_strtolower((string) $r['name']), $needle),
            default => true,
        }));

        return ['rows' => $rows, 'stats' => $stats];
    }

    /** @return array{rows: \Illuminate\Support\Collection<int, DeviceService>, stats: array{total: int, running: int, auto_stopped: int}} */
    private function services(Device $device, string $search, string $filter): array
    {
        $base = DeviceService::query()->where('device_id', $device->id);

        $stats = [
            'total' => (clone $base)->count(),
            'running' => (clone $base)->whereRaw('lower(state) = ?', ['running'])->count(),
            // "Auto" yang berhenti: kandidat masalah (sebagian layanan Auto memang berhenti sendiri setelah selesai).
            'auto_stopped' => (clone $base)->whereRaw('lower(start_mode) = ?', ['auto'])->whereRaw('lower(state) <> ?', ['running'])->count(),
        ];

        $rows = (clone $base)
            ->when($filter === 'running', fn ($q) => $q->whereRaw('lower(state) = ?', ['running']))
            ->when($filter === 'stopped', fn ($q) => $q->whereRaw('lower(state) <> ?', ['running']))
            ->when($filter === 'auto_stopped', fn ($q) => $q->whereRaw('lower(start_mode) = ?', ['auto'])->whereRaw('lower(state) <> ?', ['running']))
            ->when($search !== '', function ($q) use ($search): void {
                $like = '%'.addcslashes(mb_strtolower($search), '\\%_').'%';
                $q->where(fn ($w) => $w->whereRaw('lower(name) LIKE ?', [$like])->orWhereRaw('lower(display_name) LIKE ?', [$like]));
            })
            ->orderBy('display_name')->orderBy('name')->limit(1500)->get();

        return ['rows' => $rows, 'stats' => $stats];
    }
}
