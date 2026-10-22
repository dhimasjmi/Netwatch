<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Models\Policy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Dipakai untuk membuat dan mengubah policy; saat mengubah, nama unik mengabaikan policy itu sendiri. */
final class PolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Dibatasi di route lewat can:manage-fleet.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $current = $this->route('policy');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('policies', 'name')->ignore($current instanceof Policy ? $current->id : null)],
            'description' => ['nullable', 'string', 'max:255'],
            // Satu field cakupan: "all", "group:ID", atau "device:ID".
            'scope' => ['required', 'string', 'regex:/^(all|group:\d+|device:\d+)$/'],
        ];
    }

    /** @return array{name: string, description: string|null, device_group_id: int|null, device_id: int|null} */
    public function policyData(): array
    {
        [$kind, $id] = array_pad(explode(':', (string) $this->validated('scope'), 2), 2, null);

        return [
            'name' => trim((string) $this->validated('name')),
            'description' => $this->validated('description'),
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
