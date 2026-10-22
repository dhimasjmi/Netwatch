<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class WinlogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Diautentikasi oleh middleware "agent".
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'max:200'],
            'events.*' => ['array'],
            'events.*.log' => ['required', 'string', 'max:30'],
            'events.*.provider' => ['required', 'string', 'max:200'],
            'events.*.id' => ['required', 'integer', 'min:0'],
            'events.*.level' => ['required', 'integer', 'between:1,3'],
            'events.*.time' => ['required', 'integer'],
            'events.*.record' => ['required', 'integer', 'min:0'],
            'events.*.message' => ['nullable', 'string', 'max:1000'],
            'events.*.count' => ['nullable', 'integer', 'between:1,100000'],
        ];
    }
}
