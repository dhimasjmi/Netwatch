<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CommandStatus;
use App\Enums\CommandType;
use App\Enums\UserRole;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use App\Services\CommandService;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AgentApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENROLL_URL = '/api/agent/v1/enroll';
    private const HEARTBEAT_URL = '/api/agent/v1/heartbeat';

    public function test_device_enrolls_with_valid_token(): void
    {
        $response = $this->postJson(self::ENROLL_URL, $this->enrollPayload($this->issueToken()));

        $response->assertCreated()->assertJsonStructure(['device_id', 'api_token', 'heartbeat_interval']);
        $this->assertSame(64, strlen($response->json('api_token')));
        $this->assertDatabaseHas('devices', ['hostname' => 'PC-LAB-01', 'os_family' => 'windows']);
    }

    public function test_enrollment_with_unknown_token_is_rejected(): void
    {
        $this->postJson(self::ENROLL_URL, $this->enrollPayload(str_repeat('a', 64)))->assertForbidden();

        $this->assertDatabaseCount('devices', 0);
    }

    public function test_enrollment_token_cannot_exceed_max_uses(): void
    {
        $token = $this->issueToken(maxUses: 1);

        $this->postJson(self::ENROLL_URL, $this->enrollPayload($token))->assertCreated();
        $this->postJson(self::ENROLL_URL, $this->enrollPayload($token, 'PC-LAB-02'))->assertForbidden();
    }

    public function test_heartbeat_requires_a_valid_token(): void
    {
        $this->postJson(self::HEARTBEAT_URL, $this->heartbeatPayload())->assertUnauthorized();
        $this->withToken(str_repeat('b', 64))->postJson(self::HEARTBEAT_URL, $this->heartbeatPayload())->assertUnauthorized();
    }

    public function test_heartbeat_records_metrics_and_marks_device_online(): void
    {
        [$device, $token] = $this->enrollDevice();

        $this->withToken($token)->postJson(self::HEARTBEAT_URL, $this->heartbeatPayload())
            ->assertOk()
            ->assertJsonPath('commands', []);

        $this->assertDatabaseCount('device_heartbeats', 1);
        $this->assertTrue($device->refresh()->isOnline());
        $this->assertSame(37.5, (float) $device->latest_metrics['cpu_percent']);
    }

    public function test_heartbeat_rejects_invalid_metrics(): void
    {
        [, $token] = $this->enrollDevice();

        $payload = $this->heartbeatPayload();
        $payload['metrics']['cpu_percent'] = 250;

        $this->withToken($token)->postJson(self::HEARTBEAT_URL, $payload)->assertUnprocessable();
    }

    public function test_pending_command_is_delivered_once_and_result_is_stored(): void
    {
        [$device, $token] = $this->enrollDevice();
        $command = app(CommandService::class)->issue($device, $this->user(UserRole::Admin), CommandType::RefreshProcesses);

        $first = $this->withToken($token)->postJson(self::HEARTBEAT_URL, $this->heartbeatPayload());
        $first->assertOk()->assertJsonPath('commands.0.id', $command->public_id)->assertJsonPath('commands.0.type', 'refresh_processes');

        $this->withToken($token)->postJson(self::HEARTBEAT_URL, $this->heartbeatPayload())->assertJsonPath('commands', []);

        $this->withToken($token)
            ->postJson("/api/agent/v1/commands/{$command->public_id}/result", ['status' => 'succeeded', 'result' => ['process_count' => 42]])
            ->assertOk();

        $this->assertSame(CommandStatus::Succeeded, $command->refresh()->status);
    }

    public function test_agent_cannot_report_result_for_another_devices_command(): void
    {
        [$device] = $this->enrollDevice();
        [, $otherToken] = $this->enrollDevice('PC-LAB-02');
        $command = app(CommandService::class)->issue($device, $this->user(UserRole::Admin), CommandType::RefreshProcesses);
        $command->forceFill(['status' => CommandStatus::Dispatched])->save();

        $this->withToken($otherToken)
            ->postJson("/api/agent/v1/commands/{$command->public_id}/result", ['status' => 'failed', 'error' => 'x'])
            ->assertNotFound();
    }

    public function test_process_snapshot_is_stored_and_trimmed(): void
    {
        [$device, $token] = $this->enrollDevice();
        $keep = (int) config('netwatch.retention.snapshots_keep');

        for ($i = 0; $i < $keep + 3; $i++) {
            $this->withToken($token)->postJson('/api/agent/v1/processes', [
                'processes' => [['pid' => 4, 'name' => 'System', 'memory_mb' => 1.5]],
            ])->assertCreated();
        }

        $this->assertSame($keep, $device->snapshots()->count());
    }

    public function test_revoked_device_token_stops_working(): void
    {
        [$device, $token] = $this->enrollDevice();
        $device->forceFill(['revoked_at' => now()])->save();

        $this->withToken($token)->postJson(self::HEARTBEAT_URL, $this->heartbeatPayload())->assertUnauthorized();
    }

    // ---------- helper ----------

    /** @return array{0: Device, 1: string} */
    private function enrollDevice(string $hostname = 'PC-LAB-01'): array
    {
        $response = $this->postJson(self::ENROLL_URL, $this->enrollPayload($this->issueToken(), $hostname))->assertCreated();

        return [Device::query()->where('public_id', $response->json('device_id'))->firstOrFail(), $response->json('api_token')];
    }

    private function issueToken(int $maxUses = 5): string
    {
        return app(EnrollmentService::class)
            ->createToken($this->user(UserRole::Admin), 'tes', null, $maxUses, 24)['plain'];
    }

    private function user(UserRole $role): User
    {
        return User::query()->firstOrCreate(
            ['email' => "{$role->value}@example.test"],
            ['name' => $role->label(), 'password' => 'Password-12345', 'role' => $role, 'is_active' => true],
        );
    }

    /** @return array<string, mixed> */
    private function enrollPayload(string $token, string $hostname = 'PC-LAB-01'): array
    {
        return [
            'enrollment_token' => $token,
            'hostname' => $hostname,
            'os_family' => 'windows',
            'os_version' => 'Windows 11 Pro',
            'agent_version' => '1.0.0',
            'ip_address' => '192.168.1.20',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ];
    }

    /** @return array<string, mixed> */
    private function heartbeatPayload(): array
    {
        return [
            'agent_version' => '1.0.0',
            'metrics' => [
                'cpu_percent' => 37.5,
                'memory_used_mb' => 4096,
                'memory_total_mb' => 8192,
                'disk_used_gb' => 120,
                'disk_total_gb' => 256,
                'uptime_seconds' => 3600,
                'logged_in_user' => 'andi',
            ],
        ];
    }
}
