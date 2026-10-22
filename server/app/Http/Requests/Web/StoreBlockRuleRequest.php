<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\BlockRuleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreBlockRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Dibatasi di route lewat can:manage-fleet.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(BlockRuleType::class)],
            // Isi nilai divalidasi lebih ketat di BlockRuleGuard; di sini hanya batas dasar.
            'value' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            // Satu field cakupan: "all", "group:ID", atau "device:ID".
            'scope' => ['required', 'string', 'regex:/^(all|group:\d+|device:\d+)$/'],
        ];
    }

    /** @return array{type: string, value: string, note: string|null, device_group_id: int|null, device_id: int|null} */
    public function ruleData(): array
    {
        [$kind, $id] = array_pad(explode(':', (string) $this->validated('scope'), 2), 2, null);

        return [
            'type' => (string) $this->validated('type'),
            'value' => (string) $this->validated('value'),
            'note' => $this->validated('note'),
            'device_group_id' => $kind === 'group' ? (int) $id : null,
            'device_id' => $kind === 'device' ? (int) $id : null,
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function ($validator): void {
            $scope = (string) $this->input('scope');

            if (preg_match('/^(group|device):(\d+)$/', $scope, $m) !== 1) {
                return;
            }

            $table = $m[1] === 'group' ? 'device_groups' : 'devices';

            if (! \Illuminate\Support\Facades\DB::table($table)->where('id', (int) $m[2])->exists()) {
                $validator->errors()->add('scope', 'Cakupan yang dipilih tidak ditemukan.');
            }
        }];
    }
}
