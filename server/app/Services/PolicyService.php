<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Policy;
use App\Models\User;

final class PolicyService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /** @param  array{name: string, description?: string|null, device_group_id?: int|null, device_id?: int|null}  $data */
    public function create(User $actor, array $data): Policy
    {
        $policy = Policy::query()->create([
            'name' => $data['name'],
            'description' => ($data['description'] ?? null) ?: null,
            'is_enabled' => true,
            'device_group_id' => $data['device_group_id'] ?? null,
            'device_id' => $data['device_id'] ?? null,
            'created_by' => $actor->id,
        ]);

        $this->audit->record('policy.created', $actor, null, ['policy_id' => $policy->id, 'name' => $policy->name, 'scope' => $policy->scopeLabel()]);

        return $policy;
    }

    /** @param  array{name: string, description?: string|null, device_group_id?: int|null, device_id?: int|null}  $data */
    public function update(User $actor, Policy $policy, array $data): void
    {
        $policy->forceFill([
            'name' => $data['name'],
            'description' => ($data['description'] ?? null) ?: null,
            'device_group_id' => $data['device_group_id'] ?? null,
            'device_id' => $data['device_id'] ?? null,
        ])->save();

        $this->audit->record('policy.updated', $actor, null, ['policy_id' => $policy->id, 'name' => $policy->name, 'scope' => $policy->scopeLabel()]);
    }

    public function setEnabled(User $actor, Policy $policy, bool $enabled): void
    {
        $policy->forceFill(['is_enabled' => $enabled])->save();

        $this->audit->record($enabled ? 'policy.enabled' : 'policy.disabled', $actor, null, ['policy_id' => $policy->id, 'name' => $policy->name]);
    }

    public function delete(User $actor, Policy $policy): void
    {
        $policy->delete(); // aturan di dalamnya ikut terhapus (cascade)

        $this->audit->record('policy.deleted', $actor, null, ['policy_id' => $policy->id, 'name' => $policy->name]);
    }
}
