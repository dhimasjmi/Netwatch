<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\StoreDeviceGroupRequest;
use App\Models\DeviceGroup;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DeviceGroupController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(): View
    {
        return view('groups.index', [
            'groups' => DeviceGroup::query()->withCount('devices')->orderBy('name')->get(),
        ]);
    }

    public function store(StoreDeviceGroupRequest $request): RedirectResponse
    {
        $group = DeviceGroup::query()->create($request->validated());
        $this->audit->record('group.created', $request->user(), null, ['name' => $group->name]);

        return back()->with('status', 'Grup dibuat.');
    }

    public function destroy(Request $request, DeviceGroup $group): RedirectResponse
    {
        $name = $group->name;
        $group->delete(); // Perangkat di grup ini otomatis menjadi "tanpa grup" (ON DELETE SET NULL).
        $this->audit->record('group.deleted', $request->user(), null, ['name' => $name]);

        return back()->with('status', 'Grup dihapus.');
    }
}
