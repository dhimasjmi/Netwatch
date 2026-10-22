<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\TargetKind;
use App\Models\MonitorTarget;
use App\Support\PingProbe;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Membuat dan mengubah target pantau. Hanya alamat IP (bukan nama host) dan, bawaannya, hanya IP internal. */
final class MonitorTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Dibatasi di route lewat can:manage-fleet.
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'address' => strtolower(trim((string) $this->input('address'))),
            'port' => $this->input('method') === 'tcp' ? $this->input('port') : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $current = $this->route('target');
        $currentId = $current instanceof MonitorTarget ? $current->id : null;

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('monitor_targets', 'name')->ignore($currentId)],
            'address' => ['required', 'string', 'max:45', $this->addressRule(),
                Rule::unique('monitor_targets', 'address')->ignore($currentId)
                    ->where(fn ($q) => $q->where('method', (string) $this->input('method'))->where('port', $this->portForLookup()))],
            'kind' => ['required', Rule::enum(TargetKind::class)],
            'method' => ['required', Rule::in(['icmp', 'tcp'])],
            'port' => ['nullable', 'required_if:method,tcp', 'integer', 'between:1,65535'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'address.unique' => 'Alamat dengan cara cek yang sama sudah dipantau.',
            'port.required_if' => 'Port wajib diisi untuk cek TCP.',
        ];
    }

    /** @return array{name: string, address: string, kind: string, method: string, port: int|null, notes: string|null} */
    public function targetData(): array
    {
        $data = $this->validated();

        return [
            'name' => (string) $data['name'],
            'address' => (string) $data['address'],
            'kind' => (string) $data['kind'],
            'method' => (string) $data['method'],
            'port' => $data['method'] === 'tcp' ? (int) $data['port'] : null,
            'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
        ];
    }

    /** Port untuk pengecekan duplikat; nilai di luar jangkauan smallint dijadikan null agar kueri tidak error (galat port dilaporkan aturan 'port'). */
    private function portForLookup(): ?int
    {
        $port = filter_var($this->input('port'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

        return $port === false ? null : $port;
    }

    private function addressRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $ip = (string) $value;
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                $fail('Isi dengan alamat IP yang valid (mis. 192.168.1.1), bukan nama host.');

                return;
            }
            if (PingProbe::command($ip, false) === null) {
                $fail('Alamat ini tidak bisa dipantau (loopback, 0.0.0.0, atau multicast).');

                return;
            }
            $internal = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
            if (! $internal && ! config('netwatch.monitor.allow_public')) {
                $fail('Hanya IP internal (192.168.x.x, 10.x.x.x, 172.16-31.x.x) yang boleh dipantau. Aktifkan NETWATCH_MONITOR_ALLOW_PUBLIC bila memang perlu.');
            }
        };
    }
}
