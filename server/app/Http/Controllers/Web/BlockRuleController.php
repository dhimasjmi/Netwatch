<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\BlockRuleType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\StoreBlockRuleRequest;
use App\Models\BlockRule;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Services\BlockRuleService;
use App\Services\Exceptions\BlockRuleException;
use App\Services\PolicyStatusResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class BlockRuleController extends Controller
{
    public function __construct(private readonly BlockRuleService $rules)
    {
    }

    public function index(PolicyStatusResolver $status): View
    {
        $devices = Device::query()->active()->orderBy('hostname')->get();

        return view('policies.index', [
            'rules' => BlockRule::query()->with(['group', 'device'])->orderByDesc('is_enabled')->orderBy('type')->orderBy('value')->paginate(50),
            'groups' => DeviceGroup::query()->orderBy('name')->get(),
            'devices' => $devices,
            'types' => BlockRuleType::cases(),
            'statuses' => $devices->map(fn (Device $d): array => ['device' => $d] + $status->resolve($d))->all(),
        ]);
    }

    public function store(StoreBlockRuleRequest $request): RedirectResponse
    {
        try {
            $this->rules->create($request->user(), $request->ruleData());
        } catch (BlockRuleException $e) {
            return back()->withInput()->withErrors(['value' => $e->getMessage()]);
        }

        return back()->with('status', 'Aturan dibuat. Agent menerapkannya pada heartbeat berikutnya (kurang dari 1 menit).');
    }

    public function toggle(Request $request, BlockRule $rule): RedirectResponse
    {
        $this->rules->setEnabled($request->user(), $rule, ! $rule->is_enabled);

        return back()->with('status', $rule->is_enabled ? 'Aturan diaktifkan.' : 'Aturan dinonaktifkan.');
    }

    public function destroy(Request $request, BlockRule $rule): RedirectResponse
    {
        $this->rules->delete($request->user(), $rule);

        return back()->with('status', 'Aturan dihapus.');
    }
}
