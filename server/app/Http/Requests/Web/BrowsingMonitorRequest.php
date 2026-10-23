<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Services\BrowsingDashboardService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BrowsingMonitorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Dibatasi di route (administrator).
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'window' => ['nullable', Rule::in(array_keys(BrowsingDashboardService::WINDOWS))],
            'device' => ['nullable', 'uuid', 'exists:devices,public_id'],
            'kind' => ['nullable', Rule::in(['url', 'connection'])],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', Rule::in(BrowsingDashboardService::PER_PAGE)],
        ];
    }

    /** @return array{window: string, device: string|null, kind: string|null, q: string|null, page: int, per_page: int} */
    public function filters(): array
    {
        $v = $this->validated();

        return [
            'window' => (string) ($v['window'] ?? '1h'),
            'device' => isset($v['device']) ? (string) $v['device'] : null,
            'kind' => isset($v['kind']) ? (string) $v['kind'] : null,
            'q' => isset($v['q']) && trim((string) $v['q']) !== '' ? trim((string) $v['q']) : null,
            'page' => (int) ($v['page'] ?? 1),
            'per_page' => (int) ($v['per_page'] ?? 10),
        ];
    }
}
