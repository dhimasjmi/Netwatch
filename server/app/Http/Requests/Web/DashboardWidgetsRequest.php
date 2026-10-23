<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\DashboardWidget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DashboardWidgetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Setiap pengguna mengatur dashboard-nya sendiri.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['save', 'reset'])],
            'widgets' => ['nullable', 'array', 'max:'.count(DashboardWidget::cases())],
            'widgets.*' => ['string', Rule::enum(DashboardWidget::class)],
        ];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_values(array_map('strval', (array) ($this->validated()['widgets'] ?? [])));
    }
}
