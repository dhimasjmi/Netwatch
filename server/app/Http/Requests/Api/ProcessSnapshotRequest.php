<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class ProcessSnapshotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Diautentikasi oleh middleware "agent".
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'processes' => ['required', 'array', 'max:5000'],
            'processes.*.pid' => ['required', 'integer', 'min:0'],
            'processes.*.name' => ['required', 'string', 'max:255'],
            'processes.*.cpu_seconds' => ['nullable', 'numeric', 'min:0'],
            'processes.*.memory_mb' => ['nullable', 'numeric', 'min:0'],
            'processes.*.user' => ['nullable', 'string', 'max:255'],
        ];
    }
}
