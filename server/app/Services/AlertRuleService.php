<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AlertRule;
use App\Models\User;

final class AlertRuleService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /** @param  array{name: string, metric: string, threshold: int, duration_minutes: int, severity: string, device_group_id: int|null, device_id: int|null}  $data */
    public function create(User $actor, array $data): AlertRule
    {
        $rule = AlertRule::query()->create($data + ['is_enabled' => true, 'created_by' => $actor->id]);

        $this->audit->record('alert_rule.created', $actor, null, ['rule_id' => $rule->id, 'name' => $rule->name, 'rule' => $rule->summary(), 'scope' => $rule->scopeLabel()]);

        return $rule;
    }

    /** @param  array{name: string, metric: string, threshold: int, duration_minutes: int, severity: string, device_group_id: int|null, device_id: int|null}  $data */
    public function update(User $actor, AlertRule $rule, array $data): void
    {
        $rule->forceFill($data)->save();
        $rule->states()->delete(); // ambang/cakupan berubah: mulai penilaian dari awal

        $this->audit->record('alert_rule.updated', $actor, null, ['rule_id' => $rule->id, 'name' => $rule->name, 'rule' => $rule->summary(), 'scope' => $rule->scopeLabel()]);
    }

    public function setEnabled(User $actor, AlertRule $rule, bool $enabled): void
    {
        $rule->forceFill(['is_enabled' => $enabled])->save();

        $this->audit->record($enabled ? 'alert_rule.enabled' : 'alert_rule.disabled', $actor, null, ['rule_id' => $rule->id, 'name' => $rule->name]);
    }

    public function delete(User $actor, AlertRule $rule): void
    {
        $rule->delete();

        $this->audit->record('alert_rule.deleted', $actor, null, ['rule_id' => $rule->id, 'name' => $rule->name]);
    }
}
