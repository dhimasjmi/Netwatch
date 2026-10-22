<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

final class StoreEnrollmentTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Dibatasi di route lewat can:manage-fleet.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:100'],
            'device_group_id' => ['nullable', 'integer', 'exists:device_groups,id'],
            'max_uses' => ['required', 'integer', 'between:1,1000'],
            'ttl_hours' => ['required', 'integer', 'between:1,720'],
        ];
    }
}
