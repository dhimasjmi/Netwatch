<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class CommandResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Diautentikasi oleh middleware "agent".
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:succeeded,failed'],
            'result' => ['nullable', 'array'],
            'error' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
