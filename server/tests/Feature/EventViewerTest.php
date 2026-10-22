<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CommandType;
use App\Enums\UserRole;
use App\Models\Device;
use App\Models\Event;
use App\Models\User;
use App\Services\CommandService;
use App\Services\EnrollmentService;
use App\Services\EventRecorder;
use App\Services\RetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class EventViewerTest extends TestCase
{
    use RefreshDatabase;

    public function test_offline_and_back_online_are_recorded_with_downtime(): void
    {
        $this->travelTo(now()->startOfMinute());
        [$device, $token] = $this->enrollDevice();
        $this->heartbeat($token);

        $this->travel(10)->minutes();
        $this->artisan('netwatch:check-status')->assertSuccessful();
        $this->assertSame(1, Event::query()->where('code', 'device.offline')->where('device_id', $device->id)->count());

        $this->heartbeat($token);

        $online = Event::query()->where('code', 'device.online')->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(600, $online->details['downtime_seconds'], 5);
    }

    public function test_gap_without_scheduler_still_records_both_events(): void
    {
        $this->travelTo(now()->startOfMinute());
        [, $token] = $this->enrollDevice();
        $this->heartbeat($token);

        $this->travel(10)->minutes();
        $this->heartbeat($token);

        $this->assertSame(1, Event::query()->where('code', 'device.offline')->count());
        $this->assertSame(1, Event::query()->where('code', 'device.online')->count());
    }

    public function test_rejected_enrollment_is_persisted_as_security_event_and_audit(): void
    {
        $this->postJson('/api/agent/v1/enroll', [
            'enrollment_token' => str_repeat('x', 64), 'hostname' => 'PC-X', 'os_family' => 'windows', 'agent_version' => '1.1.0',
        ])->assertForbidden();

        $this->assertDatabaseHas('events', ['code' => 'device.enroll_rejected', 'category' => 'security', 'severity' => 'warning']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.enroll_rejected']);
    }

    public function test_failed_login_and_command_lifecycle_are_recorded(): void
    {
        $admin = $this->user(UserRole::Admin);
        $this->post('/login', ['email' => $admin->email, 'password' => 'salah-salah-1'])->assertSessionHasErrors('email');
        $this->assertDatabaseHas('events', ['code' => 'auth.login_failed']);

        [$device, $token] = $this->enrollDevice();
        $commands = app(CommandService::class);
        $command = $commands->issue($device, $admin, CommandType::RefreshProcesses);
        $this->assertDatabaseHas('events', ['code' => 'command.issued', 'device_id' => $device->id]);

        $commands->claimPending($device);
        $commands->complete($command->refresh(), false, [], 'akses ditolak');
        $this->assertDatabaseHas('events', ['code' => 'command.failed', 'device_id' => $device->id]);
    }

    public function test_viewer_cannot_see_security_events_but_admin_can(): void
    {
        app(EventRecorder::class)->record('auth.login_failed', 'Percobaan login gagal untuk "rahasia@example.test".');
        app(EventRecorder::class)->record('device.offline', 'Perangkat berhenti mengirim heartbeat.');

        $this->actingAs($this->user(UserRole::Viewer))->get('/events?preset=24h')
            ->assertOk()->assertSee('berhenti mengirim heartbeat')->assertDontSee('rahasia@example.test');

        $this->actingAs($this->user(UserRole::Admin))->get('/events?preset=24h')
            ->assertOk()->assertSee('rahasia@example.test')->assertSee('Saran troubleshooting');
    }

    public function test_filters_by_category_severity_and_device(): void
    {
        [$device] = $this->enrollDevice();
        $recorder = app(EventRecorder::class);
        $recorder->record('device.offline', 'pesan-offline-unik', $device);
        $recorder->record('command.issued', 'pesan-perintah-unik');

        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)->get('/events?category=availability')->assertSee('pesan-offline-unik')->assertDontSee('pesan-perintah-unik');
        $this->actingAs($admin)->get('/events?severity=info&category=command')->assertSee('pesan-perintah-unik')->assertDontSee('pesan-offline-unik');
        $this->actingAs($admin)->get("/events?device={$device->public_id}")->assertSee('pesan-offline-unik')->assertDontSee('pesan-perintah-unik');
    }

    public function test_invalid_filter_is_rejected(): void
    {
        $this->actingAs($this->user(UserRole::Admin))->getJson('/events?category=ngawur')->assertUnprocessable();
    }

    public function test_recorder_rejects_unknown_codes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(EventRecorder::class)->record('tidak.ada', 'x');
    }

    public function test_old_events_are_pruned(): void
    {
        $recorder = app(EventRecorder::class);
        $recorder->record('device.offline', 'lama', null, null, [], now()->subDays(400));
        $recorder->record('device.offline', 'baru');

        $deleted = app(RetentionService::class)->prune();

        $this->assertSame(1, $deleted['events']);
        $this->assertDatabaseHas('events', ['message' => 'baru']);
    }

    // ---------- helper ----------

    private function heartbeat(string $token): void
    {
        $this->withToken($token)->postJson('/api/agent/v1/heartbeat', ['metrics' => [
            'cpu_percent' => 5, 'memory_used_mb' => 1000, 'memory_total_mb' => 8000,
            'disk_used_gb' => 50, 'disk_total_gb' => 200, 'uptime_seconds' => 1000,
        ]])->assertOk();
    }

    /** @return array{0: Device, 1: string} */
    private function enrollDevice(): array
    {
        $token = app(EnrollmentService::class)->createToken($this->user(UserRole::Admin), 'tes', null, 5, 24)['plain'];

        $response = $this->postJson('/api/agent/v1/enroll', [
            'enrollment_token' => $token, 'hostname' => 'PC-EVT-01', 'os_family' => 'windows',
            'os_version' => 'Windows 11 Pro', 'agent_version' => '1.1.0',
        ])->assertCreated();

        return [Device::query()->where('public_id', $response->json('device_id'))->firstOrFail(), $response->json('api_token')];
    }

    private function user(UserRole $role): User
    {
        return User::query()->firstOrCreate(
            ['email' => "{$role->value}@example.test"],
            ['name' => $role->label(), 'password' => 'Password-12345', 'role' => $role, 'is_active' => true],
        );
    }
}
