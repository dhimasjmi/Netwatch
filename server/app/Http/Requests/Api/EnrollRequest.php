<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Enums\OsFamily;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class EnrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Dilindungi oleh token pendaftaran, bukan sesi pengguna.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enrollment_token' => ['required', 'string', 'size:64', 'alpha_num'],
            'hostname' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'os_family' => ['required', Rule::enum(OsFamily::class)],
            'os_version' => ['nullable', 'string', 'max:100'],
            'agent_version' => ['required', 'string', 'max:20'],
            'ip_address' => ['nullable', 'ip'],
            'mac_address' => ['nullable', 'mac_address'],
        ];
    }
}
