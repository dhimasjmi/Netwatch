<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class BrowsingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Diautentikasi oleh middleware "agent".
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'visits' => ['required', 'array', 'max:500'],
            'visits.*' => ['array'],
            'visits.*.kind' => ['required', 'string', 'max:12'],
            'visits.*.at' => ['required', 'string', 'max:40'],
            'visits.*.host' => ['nullable', 'string', 'max:255'],
            'visits.*.ip' => ['nullable', 'string', 'max:45'],
            'visits.*.port' => ['nullable', 'integer'],
            'visits.*.url' => ['nullable', 'string', 'max:2048'],
            'visits.*.process' => ['nullable', 'string', 'max:260'],
            'visits.*.browser' => ['nullable', 'string', 'max:12'],
        ];
    }
}
