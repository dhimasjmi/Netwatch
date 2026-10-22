<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OsFamily;
use App\Models\Device;

/** Menerjemahkan keadaan penerapan kebijakan satu perangkat menjadi status yang bisa ditampilkan. */
final class PolicyStatusResolver
{
    public function __construct(private readonly PolicyBuilder $builder)
    {
    }

    /** @return array{state: 'ok'|'error'|'pending'|'na', label: string} */
    public function resolve(Device $device): array
    {
        if ($device->os_family !== OsFamily::Windows) {
            return ['state' => 'na', 'label' => 'Tidak berlaku (hanya Windows)'];
        }

        $desired = $this->builder->forDevice($device);

        return match (true) {
            $device->policy_reported_at === null && $desired->isEmpty() => ['state' => 'na', 'label' => 'Tanpa aturan'],
            $device->policy_error !== null => ['state' => 'error', 'label' => 'Gagal: '.$device->policy_error],
            $device->policy_hash === $desired->hash() => ['state' => 'ok', 'label' => $desired->isEmpty() ? 'Tanpa aturan' : 'Diterapkan'],
            $device->policy_reported_at === null => ['state' => 'pending', 'label' => 'Menunggu agent (perlu agent 1.2.0+)'],
            default => ['state' => 'pending', 'label' => 'Menunggu heartbeat berikutnya'],
        };
    }
}
