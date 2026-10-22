<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class AssetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Diautentikasi oleh middleware "agent".
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // null = agent tidak berhasil membaca bagian itu; data lama di server dibiarkan.
            'hardware' => ['present', 'nullable', 'array'],
            'hardware.manufacturer' => ['nullable', 'string', 'max:100'],
            'hardware.model' => ['nullable', 'string', 'max:100'],
            'hardware.serial' => ['nullable', 'string', 'max:100'],
            'hardware.cpu' => ['nullable', 'string', 'max:150'],
            'hardware.cpu_cores' => ['nullable', 'integer', 'min:1', 'max:1024'],
            'hardware.cpu_threads' => ['nullable', 'integer', 'min:1', 'max:4096'],
            'hardware.memory_mb' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'hardware.os' => ['nullable', 'string', 'max:100'],
            'hardware.os_version' => ['nullable', 'string', 'max:50'],
            'hardware.os_build' => ['nullable', 'string', 'max:50'],
            'hardware.disks' => ['nullable', 'array', 'max:16'],
            'hardware.disks.*.model' => ['required', 'string', 'max:100'],
            'hardware.disks.*.size_gb' => ['required', 'integer', 'min:1', 'max:100000'],
            'software' => ['present', 'nullable', 'array', 'max:3000'],
            'software.*' => ['array'],
            'software.*.name' => ['required', 'string', 'max:300'],
            'software.*.version' => ['nullable', 'string', 'max:100'],
            'software.*.publisher' => ['nullable', 'string', 'max:200'],
            'software.*.installed' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
