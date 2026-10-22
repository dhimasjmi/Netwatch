<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AlertRule;
use App\Models\AlertState;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceHeartbeat;
use App\Models\Event;
use App\Models\User;
use App\Services\AlertEvaluator;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AlertRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_manage_rules_and_input_is_validated(): void
    {
        $this->actingAs($this->user(UserRole::Viewer))->get(route('alerts.index'))->assertForbidden();
        $this->post(route('alerts.store'), $this->rule())->assertForbidden();

        $this->actingAs($this->user(UserRole::Admin))->get(route('alerts.index'))->assertOk();
        $this->post(route('alerts.store'), ['threshold' => 150] + $this->rule())->assertSessionHasErrors('threshold');
        $this->post(route('alerts.store'), ['metric' => 'bogus'] + $this->rule())->assertSessionHasErrors('metric');
        $this->post(route('alerts.store'), ['scope' => 'device:99999'] + $this->rule())->assertSessionHasErrors('scope');
        $this->post(route('alerts.store'), $this->rule())->assertRedirect(route('alerts.index'));
        $this->post(route('alerts.store'), $this->rule())->assertSessionHasErrors('name'); // nama unik

        $this->assertDatabaseHas('audit_logs', ['action' => 'alert_rule.created']);
    }

    public function test_update_toggle_and_delete_are_audited(): void
    {
        $this->actingAs($this->user(UserRole::Admin))->post(route('alerts.store'), $this->rule());
        $rule = AlertRule::query()->sole();

        $this->patch(route('alerts.update', $rule), ['threshold' => 80] + $this->rule())->assertSessionHasNoErrors();
        $this->assertSame(80, $rule->refresh()->threshold);

        $this->post(route('alerts.toggle', $rule));
        $this->assertFalse($rule->refresh()->is_enabled);

        $this->delete(route('alerts.destroy', $rule));
        $this->assertSame(0, AlertRule::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'alert_rule.deleted')->count());
    }

    public function test_fires_once_after_sustained_breach_then_resolves(): void
    {
        $device = $this->device();
        $rule = $this->createRule();
        $this->heartbeats($device, 12, 95.0);

        $this->assertSame(['fired' => 1, 'resolved' => 0], app(AlertEvaluator::class)->run());
        $this->assertSame(['fired' => 0, 'resolved' => 0], app(AlertEvaluator::class)->run()); // tidak menyala dua kali
        $event = Event::query()->where('code', 'alert.firing')->sole();
        $this->assertSame($device->id, $event->device_id);
        $this->assertSame('critical', $event->severity->value);
        $this->assertNotNull(AlertState::query()->where('alert_rule_id', $rule->id)->sole()->firing_since);

        $this->replaceRecent($device, 20.0); // beban turun
        $this->assertSame(['fired' => 0, 'resolved' => 1], app(AlertEvaluator::class)->run());
        $this->assertSame(1, Event::query()->where('code', 'alert.resolved')->count());
    }

    public function test_short_spike_or_fresh_device_does_not_fire(): void
    {
        $device = $this->device();
        $this->createRule();

        $this->heartbeats($device, 3, 99.0); // baru 3 menit data, jendela 10 menit belum tercakup
        $this->assertSame(0, app(AlertEvaluator::class)->run()['fired']);

        DeviceHeartbeat::query()->where('device_id', $device->id)->delete();
        $this->heartbeats($device, 12, 99.0);
        DeviceHeartbeat::query()->create($this->beat($device, 10.0, now()->subMinutes(5))); // satu sampel rendah di tengah jendela
        $this->assertSame(0, app(AlertEvaluator::class)->run()['fired']);
    }

    public function test_offline_device_and_maintenance_are_skipped(): void
    {
        $offline = $this->device();
        $this->createRule();
        $this->heartbeats($offline, 12, 95.0);
        $offline->forceFill(['last_seen_at' => now()->subHour()])->save();
        $this->assertSame(0, app(AlertEvaluator::class)->run()['fired']);

        $offline->forceFill(['last_seen_at' => now(), 'maintenance_until' => now()->addHour()])->save();
        $this->assertSame(0, app(AlertEvaluator::class)->run()['fired']);
    }

    public function test_disabled_rule_clears_state_and_scope_is_respected(): void
    {
        $device = $this->device();
        $rule = $this->createRule();
        $this->heartbeats($device, 12, 95.0);
        app(AlertEvaluator::class)->run();
        $this->assertSame(1, AlertState::query()->count());

        $rule->forceFill(['is_enabled' => false])->save();
        app(AlertEvaluator::class)->run();
        $this->assertSame(0, AlertState::query()->count());

        $other = $this->device('PC-LAIN');
        $rule->forceFill(['is_enabled' => true, 'device_id' => $other->id])->save();
        $this->assertSame(0, app(AlertEvaluator::class)->run()['fired']); // device lain di luar cakupan
    }

    public function test_memory_and_disk_metrics_use_percentages(): void
    {
        $device = $this->device();
        $this->createRule(['name' => 'RAM', 'metric' => 'memory', 'threshold' => 90]);
        $this->heartbeats($device, 12, 5.0, ['memory_used_mb' => 9500, 'memory_total_mb' => 10000]);

        $this->assertSame(1, app(AlertEvaluator::class)->run()['fired']);
    }

    public function test_resolved_notification_only_if_firing_was_notified(): void
    {
        config(['netwatch.alerts.enabled' => true, 'netwatch.alerts.telegram_token' => '1:X', 'netwatch.alerts.telegram_chat_id' => '9', 'netwatch.alerts.mail_to' => []]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $device = $this->device();
        $this->createRule();
        $this->heartbeats($device, 12, 95.0);
        app(AlertEvaluator::class)->run();
        $this->artisan('netwatch:send-alerts')->assertSuccessful();
        Http::assertSent(fn ($r) => str_contains($r['text'], '⚠️'));

        $this->replaceRecent($device, 10.0);
        app(AlertEvaluator::class)->run();
        $this->artisan('netwatch:send-alerts')->assertSuccessful();
        Http::assertSent(fn ($r) => str_contains($r['text'], '✅'));
    }

    public function test_trend_page_renders_for_any_logged_in_user_and_validates_range(): void
    {
        $device = $this->device();
        $this->heartbeats($device, 10, 42.0);

        $this->actingAs($this->user(UserRole::Viewer))->get(route('devices.metrics', $device))->assertOk()->assertSee('polyline', false);
        $this->get(route('devices.metrics', ['device' => $device, 'range' => '7d']))->assertOk();
        $this->get(route('devices.metrics', ['device' => $device, 'range' => 'x; drop']))->assertOk(); // rentang tidak valid -> default
    }

    /** @param  array<string, mixed>  $extra */
    private function createRule(array $extra = []): AlertRule
    {
        return AlertRule::query()->create($extra + ['name' => 'CPU tinggi', 'metric' => 'cpu', 'threshold' => 90, 'duration_minutes' => 10, 'severity' => 'critical', 'is_enabled' => true]);
    }

    /** @return array<string, mixed> */
    private function rule(): array
    {
        return ['name' => 'CPU tinggi', 'metric' => 'cpu', 'threshold' => 90, 'duration_minutes' => 10, 'severity' => 'critical', 'scope' => 'all'];
    }

    /**
     * Heartbeat tiap 30 detik selama $minutes menit terakhir (berakhir sekarang).
     *
     * @param  array<string, mixed>  $extra
     */
    private function heartbeats(Device $device, int $minutes, float $cpu, array $extra = []): void
    {
        for ($i = $minutes * 2; $i >= 0; $i--) {
            DeviceHeartbeat::query()->create($this->beat($device, $cpu, now()->subSeconds($i * 30), $extra));
        }
        $device->forceFill(['last_seen_at' => now()])->save();
    }

    /** Mengganti sampel 3 menit terakhir dengan nilai baru (simulasi beban turun). */
    private function replaceRecent(Device $device, float $cpu): void
    {
        DeviceHeartbeat::query()->where('device_id', $device->id)->where('recorded_at', '>=', now()->subSeconds(180))->delete();
        $this->heartbeats($device, 2, $cpu);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function beat(Device $device, float $cpu, \DateTimeInterface $at, array $extra = []): array
    {
        return $extra + ['device_id' => $device->id, 'cpu_percent' => $cpu, 'memory_used_mb' => 1000, 'memory_total_mb' => 10000, 'disk_used_gb' => 100, 'disk_total_gb' => 500, 'uptime_seconds' => 1000, 'recorded_at' => $at];
    }

    private function device(string $hostname = 'PC-ALERT-01'): Device
    {
        $token = app(EnrollmentService::class)->createToken($this->user(UserRole::Admin), 'tes', null, 5, 24)['plain'];
        $response = $this->postJson('/api/agent/v1/enroll', [
            'enrollment_token' => $token, 'hostname' => $hostname, 'os_family' => 'windows', 'os_version' => 'Windows 11', 'agent_version' => '1.6.0',
        ])->assertCreated();

        return Device::query()->where('public_id', $response->json('device_id'))->firstOrFail()->forceFill(['last_seen_at' => now()]);
    }

    private function user(UserRole $role): User
    {
        return User::query()->firstOrCreate(
            ['email' => "{$role->value}@example.test"],
            ['name' => $role->label(), 'password' => 'Password-12345', 'role' => $role, 'is_active' => true],
        );
    }
}
