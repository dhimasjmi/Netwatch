<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Enums\UserRole;
use App\Models\Device;
use App\Models\DeviceStatusHistory;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\StatusTracker;
use App\Services\UptimeReportService;
use App\Support\DateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UptimeFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_outage_is_recorded_between_last_heartbeat_and_the_next_one(): void
    {
        $this->travelTo(now()->startOfMinute());
        [$device, $token] = $this->enrollDevice();

        $this->heartbeat($token);
        $lastSeenBeforeOutage = $device->refresh()->last_seen_at;

        $this->travel(10)->minutes();
        $this->artisan('netwatch:check-status')->assertSuccessful();

        $open = $this->openInterval($device);
        $this->assertSame(ConnectionStatus::Offline, $open->status);
        $this->assertSame($lastSeenBeforeOutage->getTimestamp(), $open->started_at->getTimestamp());

        $this->heartbeat($token);

        $this->assertSame(ConnectionStatus::Online, $this->openInterval($device)->status);

        $report = app(UptimeReportService::class)->forDevice($device, DateRange::fromInput(null, null, '24h', 'UTC'));

        $this->assertSame(1, $report['summary']->outageCount);
        $this->assertEqualsWithDelta(600, $report['summary']->downSeconds, 5);
        $this->assertCount(1, $report['outages']);
    }

    public function test_outage_is_recorded_even_when_scheduler_never_ran(): void
    {
        $this->travelTo(now()->startOfMinute());
        [$device, $token] = $this->enrollDevice();

        $this->heartbeat($token);
        $this->travel(10)->minutes(); // laptop sleep / WiFi mati; check-status tidak dijalankan
        $this->heartbeat($token);

        $report = app(UptimeReportService::class)->forDevice($device, DateRange::fromInput(null, null, '24h', 'UTC'));

        $this->assertSame(1, $report['summary']->outageCount);
        $this->assertEqualsWithDelta(600, $report['summary']->downSeconds, 5);
        $this->assertSame(1, DeviceStatusHistory::query()->where('device_id', $device->id)->whereNull('ended_at')->count());
    }

    public function test_short_silence_below_threshold_is_not_an_outage(): void
    {
        $this->travelTo(now()->startOfMinute());
        [$device, $token] = $this->enrollDevice();

        $this->heartbeat($token);
        $this->travel(60)->seconds();
        $this->heartbeat($token);

        $this->assertSame(0, DeviceStatusHistory::query()->where('device_id', $device->id)->where('status', ConnectionStatus::Offline->value)->count());
    }

    public function test_check_status_marks_only_stale_active_devices_and_is_idempotent(): void
    {
        $stale = $this->device('PC-STALE', now()->subMinutes(10));
        $fresh = $this->device('PC-FRESH', now());
        $revoked = $this->device('PC-REVOKED', now()->subMinutes(10), revoked: true);

        $this->artisan('netwatch:check-status')->assertSuccessful();
        $this->artisan('netwatch:check-status')->assertSuccessful();

        $this->assertSame(ConnectionStatus::Offline, $this->openInterval($stale)->status);
        $this->assertSame(2, DeviceStatusHistory::query()->where('device_id', $stale->id)->count());
        $this->assertSame(ConnectionStatus::Online, $this->openInterval($fresh)->status);
        $this->assertSame(ConnectionStatus::Online, $this->openInterval($revoked)->status);
    }

    public function test_viewer_can_open_uptime_pages(): void
    {
        $viewer = $this->user(UserRole::Viewer);
        $device = $this->device('PC-VIEW', now());

        $this->actingAs($viewer)->get(route('devices.uptime', $device))->assertOk();
        $this->actingAs($viewer)->get(route('reports.uptime', ['preset' => '30d']))->assertOk()->assertSee('PC-VIEW');
    }

    public function test_guest_cannot_open_uptime_report(): void
    {
        $this->get(route('reports.uptime'))->assertRedirect('/login');
    }

    public function test_invalid_filter_input_is_rejected_not_crashing(): void
    {
        $this->actingAs($this->user(UserRole::Viewer))
            ->from('/reports/uptime')
            ->get(route('reports.uptime', ['from' => 'bukan-tanggal', 'group' => 999999]))
            ->assertSessionHasErrors(['from', 'group']);
    }

    public function test_csv_export_has_bom_header_and_neutralises_formulas(): void
    {
        $this->device('=HYPERLINK("http://evil")', now());

        $response = $this->actingAs($this->user(UserRole::Viewer))->get(route('reports.uptime.csv', ['preset' => '7d']));

        $response->assertOk();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent());
        $this->assertStringContainsString('ketersediaan_persen', $response->streamedContent());
        $this->assertStringNotContainsString(',=HYPERLINK', $response->streamedContent());
        $this->assertStringNotContainsString('"=HYPERLINK', $response->streamedContent());
    }

    // ---------- helper ----------

    private function heartbeat(string $token): void
    {
        $this->withToken($token)->postJson('/api/agent/v1/heartbeat', [
            'agent_version' => '1.0.0',
            'metrics' => [
                'cpu_percent' => 10,
                'memory_used_mb' => 1000,
                'memory_total_mb' => 8000,
                'disk_used_gb' => 50,
                'disk_total_gb' => 200,
                'uptime_seconds' => 1000,
                'logged_in_user' => null,
            ],
        ])->assertOk();
    }

    /** @return array{0: Device, 1: string} */
    private function enrollDevice(): array
    {
        $token = app(EnrollmentService::class)
            ->createToken($this->user(UserRole::Admin), 'tes', null, 5, 24)['plain'];

        $response = $this->postJson('/api/agent/v1/enroll', [
            'enrollment_token' => $token,
            'hostname' => 'PC-UPTIME-01',
            'os_family' => 'windows',
            'os_version' => 'Windows 11 Pro',
            'agent_version' => '1.0.0',
        ])->assertCreated();

        return [Device::query()->where('public_id', $response->json('device_id'))->firstOrFail(), $response->json('api_token')];
    }

    private function device(string $hostname, \DateTimeInterface $lastSeen, bool $revoked = false): Device
    {
        $device = Device::query()->create([
            'hostname' => $hostname,
            'os_family' => 'linux',
            'agent_version' => '1.0.0',
            'token_hash' => hash('sha256', 'token-'.uniqid('', true)),
            'enrolled_at' => $lastSeen,
            'last_seen_at' => $lastSeen,
            'revoked_at' => $revoked ? now() : null,
        ]);

        app(StatusTracker::class)->markOnline($device, \Carbon\CarbonImmutable::instance($lastSeen));

        return $device;
    }

    private function openInterval(Device $device): DeviceStatusHistory
    {
        return DeviceStatusHistory::query()->where('device_id', $device->id)->whereNull('ended_at')->firstOrFail();
    }

    private function user(UserRole $role): User
    {
        return User::query()->firstOrCreate(
            ['email' => "{$role->value}@example.test"],
            ['name' => $role->label(), 'password' => 'Password-12345', 'role' => $role, 'is_active' => true],
        );
    }
}
