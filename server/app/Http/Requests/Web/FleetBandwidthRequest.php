<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Services\FleetBandwidthService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class FleetBandwidthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'window' => ['nullable', Rule::in(array_keys(FleetBandwidthService::WINDOWS))],
            'group' => ['nullable', 'integer', 'exists:device_groups,id'],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(FleetBandwidthService::SORTS)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', Rule::in(FleetBandwidthService::PER_PAGE)],
            'devices' => ['nullable', 'array', 'max:200'],
            'devices.*' => ['string', 'uuid'],
            'only' => ['nullable', 'boolean'],
        ];
    }

    /** @return array{window: string, group: int|null, q: string|null, sort: string, dir: string, page: int, per_page: int, devices: list<string>, only: bool} */
    public function filters(): array
    {
        $v = $this->validated();
        $devices = array_values(array_unique(array_map('strval', $v['devices'] ?? [])));
        sort($devices); // urutan tetap agar kunci cache sama

        return [
            'window' => (string) ($v['window'] ?? '1h'),
            'group' => isset($v['group']) ? (int) $v['group'] : null,
            'q' => isset($v['q']) && trim((string) $v['q']) !== '' ? trim((string) $v['q']) : null,
            'sort' => (string) ($v['sort'] ?? 'total'),
            'dir' => (string) ($v['dir'] ?? 'desc'),
            'page' => (int) ($v['page'] ?? 1),
            'per_page' => (int) ($v['per_page'] ?? 10),
            'devices' => $devices,
            'only' => (bool) ($v['only'] ?? false),
        ];
    }
}
