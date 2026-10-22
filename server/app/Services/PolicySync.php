<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OsFamily;
use App\Models\Device;
use App\Support\DevicePolicy;

/**
 * Sinkronisasi kebijakan blokir lewat heartbeat: agent melaporkan hash yang sudah diterapkan,
 * server membalas dengan kebijakan penuh hanya bila hash-nya berbeda dari yang seharusnya.
 */
final class PolicySync
{
    public function __construct(private readonly PolicyBuilder $builder, private readonly EventRecorder $events)
    {
    }

    /**
     * @param  array{hash?: string|null, error?: string|null, offline_release?: bool|null}|null  $report  null = agent lama yang belum mengenal kebijakan
     * @return array{hash: string, domains: list<string>, ips: list<string>}|null kebijakan yang harus diterapkan agent, atau null
     */
    public function sync(Device $device, ?array $report): ?array
    {
        if ($report === null || $device->os_family !== OsFamily::Windows) {
            return null; // Penerapan baru tersedia untuk agent Windows yang melaporkan status kebijakan.
        }

        $desired = $this->builder->forDevice($device);
        $reportedHash = $report['hash'] ?? null;

        $this->recordReport($device, $desired, $reportedHash, $report['error'] ?? null, ($report['offline_release'] ?? false) === true);

        // Hash null TIDAK berarti bersih: bisa jadi state agent hilang (reinstal) sementara aturan masih terpasang.
        // Agent 1.5+ melaporkan hash dari aturan yang benar-benar terpasang (kebijakan kosong pun punya hash),
        // jadi hanya hash yang sama persis yang dianggap sinkron; selain itu keadaan yang seharusnya dikirim (idempoten).
        if ($desired->hash() === $reportedHash) {
            return null;
        }

        return $desired->toPayload();
    }

    private function recordReport(Device $device, DevicePolicy $desired, ?string $hash, ?string $error, bool $offlineRelease = false): void
    {
        $previousHash = $device->policy_hash;
        $previousError = $device->policy_error;

        if ($hash === $previousHash && $error === $previousError) {
            return;
        }

        $device->forceFill(['policy_hash' => $hash, 'policy_error' => $error, 'policy_reported_at' => now()])->save();

        if ($error === null && $offlineRelease && $hash !== $previousHash) {
            $this->events->record('policy.offline_release', 'Blokir dilepas otomatis: perangkat di luar jaringan kantor (server tidak terjangkau). Dipasang kembali saat terhubung.', $device, null, ['previous' => $previousHash]);

            return;
        }

        if ($error === null && $previousHash === $desired->hash() && $hash !== $previousHash) {
            $this->events->record('policy.drift', 'Aturan di komputer menyimpang dari kebijakan (diubah/dihapus manual atau state agent hilang); diterapkan ulang.', $device, null, ['previous' => $previousHash, 'reported' => $hash]);

            return;
        }

        if ($error !== null && $error !== $previousError) {
            $this->events->record('policy.failed', 'Gagal menerapkan kebijakan blokir: '.$error, $device, null, ['error' => $error]);

            return;
        }

        if ($error === null && $hash !== null && $hash !== $previousHash) {
            $matches = $hash === $desired->hash();
            $this->events->record('policy.applied', $matches
                ? sprintf('Kebijakan blokir diterapkan (%d domain, %d IP).', count($desired->domains), count($desired->ips))
                : 'Kebijakan blokir diterapkan (versi sebelumnya; pembaruan menyusul).', $device, null, ['hash' => $hash]);
        }
    }
}
