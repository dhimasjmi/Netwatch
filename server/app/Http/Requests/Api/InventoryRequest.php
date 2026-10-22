<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class InventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Diautentikasi oleh middleware "agent".
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ports' => ['present', 'array', 'max:3000'],
            'ports.*' => ['array'],
            'ports.*.protocol' => ['required', 'string', 'max:3'],
            'ports.*.address' => ['required', 'string', 'max:64'],
            'ports.*.port' => ['required', 'integer'],
            'ports.*.process' => ['nullable', 'string', 'max:260'],
            'services' => ['present', 'array', 'max:1500'],
            'services.*' => ['array'],
            'services.*.name' => ['nullable', 'string', 'max:300'],
            'services.*.display' => ['nullable', 'string', 'max:300'],
            'services.*.state' => ['nullable', 'string', 'max:30'],
            'services.*.start' => ['nullable', 'string', 'max:30'],
        ];
    }
}
