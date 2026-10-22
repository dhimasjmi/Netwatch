<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Support\DateRange;
use Illuminate\Foundation\Http\FormRequest;

final class BrowsingFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Dibatasi di route lewat can:manage-fleet.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'preset' => ['nullable', 'in:24h,7d,30d'],
            'device' => ['nullable', 'string', 'max:64', 'exists:devices,public_id'],
            'kind' => ['nullable', 'in:url,connection'],
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function range(): DateRange
    {
        return DateRange::fromInput(
            $this->input('from'),
            $this->input('to'),
            $this->input('preset') ?? '24h',
            (string) config('netwatch.display_timezone'),
            maxDays: 90,
        );
    }
}
