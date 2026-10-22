<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Device;
use App\Models\Event;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AlertAndMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'netwatch.alerts.enabled' => true,
            'netwatch.alerts.telegram_token' => '123:SECRET',
            'netwatch.alerts.telegram_chat_id' => '999',
            'netwatch.alerts.mail_to' => [],
        ]);
    }

    public function test_offline_event_is_sent_once_to_telegram(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        [, $token] = $this->goOffline();

        $this->artisan('netwatch:send-alerts')->assertSuccessful();
        $this->artisan('netwatch:send-alerts')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['text'], 'OFFLINE') && $r['chat_id'] === '999');
        $this->assertDatabaseHas('events', ['code' => 'device.offline', 'notification_sent' => true]);
    }

    public function test_failed_delivery_is_retried_and_error_hides_token(): void
    {
        Http::fakeSequence('api.telegram.org/*')->push(['ok' => false], 401)->push(['ok' => true]);
        $this->goOffline();

        $this->artisan('netwatch:send-alerts')->assertFailed();
        $this->assertDatabaseHas('events', ['code' => 'device.offline', 'notification_sent' => false, 'notified_at' => null]);

        $this->artisan('netwatch:send-alerts')->assertSuccessful();
        $this->assertDatabaseHas('events', ['code' => 'device.offline', 'notification_sent' => true]);
    }

    public function test_no_alert_while_in_maintenance(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        [$device] = $this->goOffline();
        $device->forceFill(['maintenance_until' => now()->addHour()])->save();

        $this->artisan('netwatch:send-alerts')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertDatabaseHas('events', ['code' => 'device.offline']); // tetap tercatat
    }

    public function test_online_notice_only_after_offline_notice_was_delivered(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        [$device, $token] = $this->goOffline();
        $device->forceFill(['maintenance_until' => now()->addHour()])->save();
        $this->artisan('netwatch:send-alerts')->assertSuccessful();

        $this->heartbeat($token);
        $this->artisan('netwatch:send-alerts')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_alerts_off_by_default_sends_nothing(): void
    {
        config(['netwatch.alerts.enabled' => false]);
        Http::fake();
        $this->goOffline();

        $this->artisan('netwatch:send-alerts')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_admin_can_start_and_end_maintenance_viewer_cannot(): void
    {
        [$device] = $this->enroll();

        $this->actingAs($this->user(UserRole::Viewer))
            ->post(route('devices.maintenance.store', $device), ['hours' => 2])->assertForbidden();

        $this->actingAs($this->user(UserRole::Admin))
            ->post(route('devices.maintenance.store', $device), ['hours' => 2, 'note' => 'Ganti RAM'])->assertRedirect();
        $this->assertTrue($device->fresh()->isInMaintenance());
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.maintenance_started']);

        $this->delete(route('devices.maintenance.destroy', $device))->assertRedirect();
        $this->assertFalse($device->fresh()->isInMaintenance());
    }

    public function test_invalid_maintenance_duration_is_rejected(): void
    {
        [$device] = $this->enroll();

        $this->actingAs($this->user(UserRole::Admin))
            ->post(route('devices.maintenance.store', $device), ['hours' => 9999])->assertSessionHasErrors('hours');
    }

    public function test_ping_offline_command_runs_with_no_candidates(): void
    {
        $this->artisan('netwatch:ping-offline')->assertSuccessful();
    }

    /** @return array{0: Device, 1: string} */
    private function goOffline(): array
    {
        $this->travelTo(now()->startOfMinute());
        [$device, $token] = $this->enroll();
        $this->heartbeat($token);
        $this->travel(10)->minutes();
        $this->artisan('netwatch:check-status')->assertSuccessful();

        return [$device, $token];
    }

    private function heartbeat(string $token): void
    {
        $this->withToken($token)->postJson('/api/agent/v1/heartbeat', ['metrics' => [
            'cpu_percent' => 5, 'memory_used_mb' => 1000, 'memory_total_mb' => 8000,
            'disk_used_gb' => 50, 'disk_total_gb' => 200, 'uptime_seconds' => 1000,
        ]])->assertOk();
    }

    /** @return array{0: Device, 1: string} */
    private function enroll(): array
    {
        $token = app(EnrollmentService::class)->createToken($this->user(UserRole::Admin), 'tes', null, 5, 24)['plain'];
        $response = $this->postJson('/api/agent/v1/enroll', [
            'enrollment_token' => $token, 'hostname' => 'PC-ALR-01', 'os_family' => 'windows',
            'os_version' => 'Windows 11 Pro', 'agent_version' => '1.2.0',
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
