<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\AddPolicyEntriesRequest;
use App\Http\Requests\Web\PolicyRequest;
use App\Models\BlockRule;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Policy;
use App\Services\BlockRuleService;
use App\Services\PolicyService;
use App\Services\PolicyStatusResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PolicyController extends Controller
{
    public function __construct(private readonly PolicyService $policies, private readonly BlockRuleService $rules)
    {
    }

    public function index(PolicyStatusResolver $status): View
    {
        $devices = Device::query()->active()->orderBy('hostname')->get();

        return view('policies.index', [
            'policies' => Policy::query()->with(['group', 'device', 'rules' => fn ($q) => $q->orderBy('type')->orderBy('value')])->orderBy('name')->get(),
            'groups' => DeviceGroup::query()->orderBy('name')->get(),
            'devices' => $devices,
            'statuses' => $devices->map(fn (Device $d): array => ['device' => $d] + $status->resolve($d))->all(),
        ]);
    }

    public function store(PolicyRequest $request): RedirectResponse
    {
        $policy = $this->policies->create($request->user(), $request->policyData());

        return redirect()->to(route('policies.index').'#policy-'.$policy->id)
            ->with('status', "Policy \"{$policy->name}\" dibuat. Tambahkan situs atau IP di dalamnya.");
    }

    public function update(PolicyRequest $request, Policy $policy): RedirectResponse
    {
        $this->policies->update($request->user(), $policy, $request->policyData());

        return back()->with('status', 'Policy diperbarui. Perubahan cakupan berlaku pada heartbeat berikutnya.');
    }

    public function toggle(Request $request, Policy $policy): RedirectResponse
    {
        $this->policies->setEnabled($request->user(), $policy, ! $policy->is_enabled);

        return back()->with('status', $policy->is_enabled ? 'Policy diaktifkan.' : 'Policy dinonaktifkan; blokirnya dicabut pada heartbeat berikutnya.');
    }

    public function destroy(Request $request, Policy $policy): RedirectResponse
    {
        $this->policies->delete($request->user(), $policy);

        return redirect()->route('policies.index')->with('status', 'Policy dihapus.');
    }

    public function addEntries(AddPolicyEntriesRequest $request, Policy $policy): RedirectResponse
    {
        $result = $this->rules->addEntries($request->user(), $policy, (string) $request->validated('entries'), $request->validated('note'));

        $redirect = back();

        if ($result['added'] !== []) {
            $redirect->with('status', count($result['added']).' target ditambahkan: '.implode(', ', array_slice($result['added'], 0, 10))
                .(count($result['added']) > 10 ? ', ...' : '').'. Agent menerapkannya pada heartbeat berikutnya.');
        }

        if ($result['errors'] !== []) {
            $redirect->withErrors(array_map(
                static fn (string $entry, string $reason): string => "{$entry}: {$reason}",
                array_keys($result['errors']),
                $result['errors'],
            ))->withInput();
        }

        return $redirect;
    }

    public function removeEntry(Request $request, Policy $policy, BlockRule $rule): RedirectResponse
    {
        abort_unless($rule->policy_id === $policy->id, 404);

        $this->rules->delete($request->user(), $rule);

        return back()->with('status', 'Target dihapus dari policy.');
    }
}
