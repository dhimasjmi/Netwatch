<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\AlertMetric;
use App\Models\AlertRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Membuat dan mengubah aturan alert; saat mengubah, nama unik mengabaikan aturan itu sendiri. */
final class AlertRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Dibatasi di route lewat can:manage-fleet.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $current = $this->route('rule');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('alert_rules', 'name')->ignore($current instanceof AlertRule ? $current->id : null)],
            'metric' => ['required', Rule::enum(AlertMetric::class)],
            'threshold' => ['required', 'integer', 'between:1,100'],
            'duration_minutes' => ['required', 'integer', 'between:1,1440'],
            'severity' => ['required', Rule::in(['warning', 'critical'])],
            'scope' => ['required', 'string', 'regex:/^(all|group:\d+|device:\d+)$/'],
        ];
    }

    /** @return array{name: string, metric: string, threshold: int, duration_minutes: int, severity: string, device_group_id: int|null, device_id: int|null} */
    public function ruleData(): array
    {
        [$kind, $id] = array_pad(explode(':', (string) $this->validated('scope'), 2), 2, null);

        return [
            'name' => trim((string) $this->validated('name')),
            'metric' => (string) $this->validated('metric'),
            'threshold' => (int) $this->validated('threshold'),
            'duration_minutes' => (int) $this->validated('duration_minutes'),
            'severity' => (string) $this->validated('severity'),
            'device_group_id' => $kind === 'group' ? (int) $id : null,
            'device_id' => $kind === 'device' ? (int) $id : null,
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function ($validator): void {
            if (preg_match('/^(group|device):(\d+)$/', (string) $this->input('scope'), $m) !== 1) {
                return;
            }

            if (! DB::table($m[1] === 'group' ? 'device_groups' : 'devices')->where('id', (int) $m[2])->exists()) {
                $validator->errors()->add('scope', 'Cakupan yang dipilih tidak ditemukan.');
            }
        }];
    }
}
