<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class HeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Diautentikasi oleh middleware "agent".
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'agent_version' => ['nullable', 'string', 'max:20'],
            'metrics' => ['required', 'array'],
            'metrics.cpu_percent' => ['required', 'numeric', 'between:0,100'],
            'metrics.memory_used_mb' => ['required', 'integer', 'min:0'],
            'metrics.memory_total_mb' => ['required', 'integer', 'min:0'],
            'metrics.disk_used_gb' => ['required', 'integer', 'min:0'],
            'metrics.disk_total_gb' => ['required', 'integer', 'min:0'],
            'metrics.uptime_seconds' => ['required', 'integer', 'min:0'],
            'metrics.logged_in_user' => ['nullable', 'string', 'max:255'],
            'policy' => ['nullable', 'array'],
            'policy.hash' => ['nullable', 'string', 'size:64', 'alpha_num'],
            'policy.error' => ['nullable', 'string', 'max:500'],
            'policy.offline_release' => ['nullable', 'boolean'],
            'interfaces' => ['nullable', 'array', 'max:32'],
            'interfaces.*.name' => ['required', 'string', 'max:100'],
            'interfaces.*.rx_bytes' => ['required', 'integer', 'min:0'],
            'interfaces.*.tx_bytes' => ['required', 'integer', 'min:0'],
            'interfaces.*.speed_bps' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
