<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\EventCategory;
use App\Enums\EventSeverity;
use App\Support\DateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class EventFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'preset' => ['nullable', 'in:24h,7d,30d'],
            'category' => ['nullable', Rule::enum(EventCategory::class)],
            'severity' => ['nullable', Rule::enum(EventSeverity::class)],
            'device' => ['nullable', 'string', 'max:64', 'exists:devices,public_id'],
        ];
    }

    public function range(): DateRange
    {
        return DateRange::fromInput(
            $this->input('from'),
            $this->input('to'),
            $this->input('preset') ?? '24h',
            (string) config('netwatch.display_timezone'),
        );
    }
}
