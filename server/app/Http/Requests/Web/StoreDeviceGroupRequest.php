<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDeviceGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Dibatasi di route lewat can:manage-fleet.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'unique:device_groups,name'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
