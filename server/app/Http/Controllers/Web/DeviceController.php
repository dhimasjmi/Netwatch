<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\UpdateDeviceRequest;
use App\Http\Resources\DeviceLiveResource;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceGroup;
use App\Services\DeviceService;
use App\Services\PolicyStatusResolver;
use App\Support\Sparkline;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DeviceController extends Controller
{
    private const PAGE_SIZE = 50;
    private const TREND_WINDOW_MINUTES = 60;

    public function index(Request $request): View
    {
        $search = trim($request->string('q')->toString());
        $status = $request->string('status')->toString();
        $onlineSince = now()->subSeconds((int) config('netwatch.offline_after'));

        $devices = Device::query()
            ->active()
            ->with('group')
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn ($q) => $q->where('hostname', 'ilike', $like)->orWhere('display_name', 'ilike', $like));
            })
            ->when($request->integer('group') > 0, fn ($q) => $q->where('device_group_id', $request->integer('group')))
            ->when($status === 'online', fn ($q) => $q->where('last_seen_at', '>', $onlineSince))
            ->when($status === 'offline', fn ($q) => $q->where(
                fn ($w) => $w->whereNull('last_seen_at')->orWhere('last_seen_at', '<=', $onlineSince)
            ))
            ->orderBy('hostname')
            ->paginate(self::PAGE_SIZE)
            ->withQueryString()
            ->through(static fn (Device $device): array => [
                'device' => $device,
                'live' => (new DeviceLiveResource($device))->resolve(),
            ]);

        return view('devices.index', [
            'devices' => $devices,
            'groups' => DeviceGroup::query()->orderBy('name')->get(),
            'filters' => ['q' => $search, 'status' => $status, 'group' => $request->integer('group')],
        ]);
    }

    public function show(Device $device, PolicyStatusResolver $policyStatus): View
    {
        abort_if($device->revoked_at !== null, 404);

        $device->load('group');

        $trend = $device->heartbeats()
            ->where('recorded_at', '>', now()->subMinutes(self::TREND_WINDOW_MINUTES))
            ->orderBy('recorded_at')
            ->get(['cpu_percent', 'memory_used_mb', 'memory_total_mb']);

        return view('devices.show', [
            'device' => $device,
            'live' => (new DeviceLiveResource($device))->resolve(),
            'groups' => DeviceGroup::query()->orderBy('name')->get(),
            'cpuPoints' => Sparkline::points($trend->pluck('cpu_percent')->all()),
            'memoryPoints' => Sparkline::points($trend->map(
                static fn ($h): float => $h->memory_total_mb > 0 ? $h->memory_used_mb / $h->memory_total_mb * 100 : 0.0
            )->all()),
            'commands' => $this->recentCommands($device),
            'policyStatus' => $policyStatus->resolve($device),
        ]);
    }

    /** Status semua perangkat untuk polling halaman daftar. */
    public function liveIndex(): JsonResponse
    {
        $devices = Device::query()
            ->active()
            ->get(['id', 'public_id', 'last_seen_at', 'revoked_at', 'latest_metrics']);

        return response()->json(['data' => DeviceLiveResource::collection($devices)->resolve()]);
    }

    /** Status, proses, dan riwayat perintah satu perangkat untuk polling halaman detail. */
    public function live(Device $device): JsonResponse
    {
        abort_if($device->revoked_at !== null, 404);

        $snapshot = $device->latestSnapshot()->first();

        return response()->json([
            'device' => (new DeviceLiveResource($device))->resolve(),
            'snapshot' => $snapshot === null ? null : [
                'captured_at' => $snapshot->captured_at->toIso8601String(),
                'captured_label' => $snapshot->captured_at->toDisplay(),
                'processes' => $snapshot->processes,
            ],
            'commands' => $this->recentCommands($device)->map(static fn (DeviceCommand $c): array => [
                'id' => $c->public_id,
                'type' => $c->type->label(),
                'status' => $c->status->value,
                'status_label' => $c->status->label(),
                'requester' => $c->requester?->name,
                'created_at' => $c->created_at?->toDisplay(),
                'error' => $c->error_message,
            ])->values(),
        ]);
    }

    public function update(UpdateDeviceRequest $request, Device $device, DeviceService $devices): RedirectResponse
    {
        $devices->update($device, $request->validated(), $request->user());

        return redirect()->route('devices.show', $device)->with('status', 'Perangkat diperbarui.');
    }

    public function destroy(Request $request, Device $device, DeviceService $devices): RedirectResponse
    {
        $devices->revoke($device, $request->user());

        return redirect()->route('devices.index')->with('status', 'Akses perangkat dicabut.');
    }

    /** @return Collection<int, DeviceCommand> */
    private function recentCommands(Device $device): Collection
    {
        return $device->commands()->with('requester')->latest()->limit(10)->get();
    }
}
