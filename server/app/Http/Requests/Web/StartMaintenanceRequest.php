<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StartMaintenanceRequest extends FormRequest
{
    public const HOURS = [1, 2, 4, 8, 24, 72];

    public function authorize(): bool
    {
        return true; // Dibatasi di route lewat can:manage-fleet.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hours' => ['required', 'integer', Rule::in(self::HOURS)],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
