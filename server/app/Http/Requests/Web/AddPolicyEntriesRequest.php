<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

final class AddPolicyEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Dibatasi di route lewat can:manage-fleet.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Tiap target divalidasi ketat di BlockRuleGuard; di sini hanya batas ukuran.
            'entries' => ['required', 'string', 'max:20000'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
