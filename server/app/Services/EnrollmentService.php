<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\EnrollmentToken;
use App\Models\User;
use App\Services\Exceptions\EnrollmentException;
use Illuminate\Support\Facades\DB;

final class EnrollmentService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly AuditLogger $audit,
        private readonly StatusTracker $status,
        private readonly EventRecorder $events,
    ) {
    }

    /**
     * Buat token pendaftaran. Token polos hanya dikembalikan sekali di sini.
     *
     * @return array{token: EnrollmentToken, plain: string}
     */
    public function createToken(User $creator, string $label, ?int $groupId, int $maxUses, int $ttlHours): array
    {
        $plain = $this->tokens->generate();

        $token = EnrollmentToken::query()->create([
            'label' => $label,
            'token_hash' => $this->tokens->hash($plain),
            'device_group_id' => $groupId,
            'max_uses' => $maxUses,
            'used_count' => 0,
            'expires_at' => now()->addHours($ttlHours),
            'created_by' => $creator->id,
        ]);

        $this->audit->record('enrollment_token.created', $creator, null, [
            'label' => $label,
            'max_uses' => $maxUses,
            'ttl_hours' => $ttlHours,
        ]);

        return ['token' => $token, 'plain' => $plain];
    }

    /**
     * Daftarkan perangkat. Bila hostname aktif yang sama sudah ada, perangkat itu didaftarkan
     * ulang dan token lamanya otomatis tidak berlaku lagi.
     *
     * @param  array{enrollment_token: string, hostname: string, os_family: string, os_version?: string|null, agent_version: string, ip_address?: string|null, mac_address?: string|null}  $data
     * @return array{device: Device, plain_token: string}
     *
     * @throws EnrollmentException
     */
    public function enroll(array $data, ?string $ip): array
    {
        try {
            return $this->enrollInTransaction($data, $ip);
        } catch (EnrollmentException $e) {
            // Dicatat di luar transaksi; bila di dalam, pencatatan ikut dibatalkan oleh rollback.
            $this->audit->record('device.enroll_rejected', null, null, ['hostname' => $data['hostname']], $ip);
            $this->events->record('device.enroll_rejected', "Pendaftaran perangkat \"{$data['hostname']}\" ditolak (token tidak valid).", null, null,
                ['hostname' => $data['hostname'], 'ip' => $ip]);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{device: Device, plain_token: string}
     *
     * @throws EnrollmentException
     */
    private function enrollInTransaction(array $data, ?string $ip): array
    {
        return DB::transaction(function () use ($data, $ip): array {
            $token = EnrollmentToken::query()
                ->where('token_hash', $this->tokens->hash($data['enrollment_token']))
                ->lockForUpdate()
                ->first();

            if ($token === null || ! $token->isUsable()) {
                throw new EnrollmentException();
            }

            $token->increment('used_count');

            $plain = $this->tokens->generate();

            $device = Device::query()->active()
                ->where('hostname', $data['hostname'])
                ->lockForUpdate()
                ->first() ?? new Device();

            $device->fill([
                'device_group_id' => $token->device_group_id ?? $device->device_group_id,
                'enrollment_token_id' => $token->id,
                'hostname' => $data['hostname'],
                'os_family' => $data['os_family'],
                'os_version' => $data['os_version'] ?? null,
                'agent_version' => $data['agent_version'],
                'ip_address' => $data['ip_address'] ?? $ip,
                'mac_address' => $data['mac_address'] ?? null,
                'token_hash' => $this->tokens->hash($plain),
                'enrolled_at' => now(),
                'last_seen_at' => now(),
            ])->save();

            $this->status->markOnline($device, now(), 'enrolled');
            $this->audit->record('device.enrolled', null, $device, ['hostname' => $device->hostname], $ip);
            $this->events->record('device.enrolled', "Perangkat \"{$device->hostname}\" terdaftar.", $device, null, ['ip' => $ip]);

            return ['device' => $device, 'plain_token' => $plain];
        });
    }

    public function revokeToken(EnrollmentToken $token, User $actor): void
    {
        $token->forceFill(['revoked_at' => now()])->save();
        $this->audit->record('enrollment_token.revoked', $actor, null, ['label' => $token->label]);
    }
}
