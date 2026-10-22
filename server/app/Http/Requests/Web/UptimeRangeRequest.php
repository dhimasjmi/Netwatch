<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Support\DateRange;
use Illuminate\Foundation\Http\FormRequest;

final class UptimeRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'preset' => ['nullable', 'in:24h,7d,30d'],
            'group' => ['nullable', 'integer', 'exists:device_groups,id'],
        ];
    }

    public function range(): DateRange
    {
        return DateRange::fromInput(
            $this->input('from'),
            $this->input('to'),
            $this->input('preset'),
            (string) config('netwatch.display_timezone'),
        );
    }
}
