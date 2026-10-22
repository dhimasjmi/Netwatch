<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\AlertRuleRequest;
use App\Models\AlertRule;
use App\Models\AlertState;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Services\AlertRuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AlertRuleController extends Controller
{
    public function __construct(private readonly AlertRuleService $rules)
    {
    }

    public function index(): View
    {
        return view('alerts.index', [
            'rules' => AlertRule::query()->with(['group', 'device'])->withCount(['states as firing_count' => fn ($q) => $q->whereNotNull('firing_since')])->orderBy('name')->get(),
            'firing' => AlertState::query()->with(['rule', 'device'])->whereNotNull('firing_since')->orderByDesc('firing_since')->limit(100)->get(),
            'groups' => DeviceGroup::query()->orderBy('name')->get(),
            'devices' => Device::query()->active()->orderBy('hostname')->get(),
        ]);
    }

    public function store(AlertRuleRequest $request): RedirectResponse
    {
        $rule = $this->rules->create($request->user(), $request->ruleData());

        return redirect()->route('alerts.index')->with('status', "Aturan alert \"{$rule->name}\" dibuat. Dievaluasi tiap menit.");
    }

    public function update(AlertRuleRequest $request, AlertRule $rule): RedirectResponse
    {
        $this->rules->update($request->user(), $rule, $request->ruleData());

        return back()->with('status', 'Aturan alert diperbarui.');
    }

    public function toggle(Request $request, AlertRule $rule): RedirectResponse
    {
        $this->rules->setEnabled($request->user(), $rule, ! $rule->is_enabled);

        return back()->with('status', $rule->is_enabled ? 'Aturan diaktifkan.' : 'Aturan dinonaktifkan.');
    }

    public function destroy(Request $request, AlertRule $rule): RedirectResponse
    {
        $this->rules->delete($request->user(), $rule);

        return redirect()->route('alerts.index')->with('status', 'Aturan alert dihapus.');
    }
}
