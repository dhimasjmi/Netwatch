<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Device;
use App\Models\DevicePort;
use App\Models\DeviceService;
use App\Models\Event;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InventoryAndWinlogTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_snapshot_replaces_previous_and_keeps_first_seen(): void
    {
        [$device, $token] = $this->enroll();

        $this->inventory($token, [$this->port(3389), $this->port(5432, '127.0.0.1')], [$this->service('Spooler', 'Running'), $this->service('Fax', 'Stopped')])
            ->assertCreated()->assertJson(['ports' => 2, 'services' => 2]);
        $first = DevicePort::query()->where('device_id', $device->id)->where('port', 3389)->firstOrFail()->first_seen_at;

        $this->travel(10)->minutes();
        $this->inventory($token, [$this->port(3389)], [$this->service('Spooler', 'Stopped')])->assertCreated();

        $this->assertSame([3389], DevicePort::query()->where('device_id', $device->id)->pluck('port')->all());
        $this->assertEquals($first->timestamp, DevicePort::query()->where('port', 3389)->firstOrFail()->first_seen_at->timestamp);
        $this->assertSame(['Spooler' => 'Stopped'], DeviceService::query()->where('device_id', $device->id)->pluck('state', 'name')->all());
        $this->assertNotNull($device->refresh()->inventory_reported_at);
    }

    public function test_new_exposed_port_after_baseline_raises_one_event_with_cooldown(): void
    {
        [$device, $token] = $this->enroll();

        $this->inventory($token, [$this->port(445)], [])->assertCreated();
        $this->assertSame(0, Event::query()->where('code', 'device.port_opened')->count()); // snapshot pertama = baseline

        $this->inventory($token, [$this->port(445), $this->port(3389, '0.0.0.0', 'svchost.exe'), $this->port(8000, '127.0.0.1'), $this->port(50000)], [])->assertCreated();
        $event = Event::query()->where('code', 'device.port_opened')->sole();
        $this->assertSame($device->id, $event->device_id);
        $this->assertSame(3389, $event->details['port']);
        $this->assertSame('security', $event->category->value);
        $this->assertStringContainsString('RDP', $event->hint() ?? '');

        // Ditutup lalu dibuka lagi dalam 24 jam: tidak diumumkan ulang.
        $this->inventory($token, [$this->port(445)], [])->assertCreated();
        $this->inventory($token, [$this->port(445), $this->port(3389)], [])->assertCreated();
        $this->assertSame(1, Event::query()->where('code', 'device.port_opened')->count());
    }

    public function test_invalid_inventory_rows_are_ignored(): void
    {
        [$device, $token] = $this->enroll();

        $this->inventory($token, [['protocol' => 'tcp', 'address' => 'x', 'port' => 80], $this->port(80)], [['name' => '', 'state' => 'Running']])->assertCreated()
            ->assertJson(['ports' => 1, 'services' => 0]);
        $this->assertSame(1, DevicePort::query()->where('device_id', $device->id)->count());
    }

    public function test_only_admin_sees_inventory_page(): void
    {
        [$device, $token] = $this->enroll();
        $this->inventory($token, [$this->port(3389)], [$this->service('Spooler', 'Running')])->assertCreated();

        $this->actingAs($this->user(UserRole::Viewer))->get(route('devices.inventory', $device))->assertForbidden();

        $this->actingAs($this->user(UserRole::Admin))->get(route('devices.inventory', $device))->assertOk()->assertSee('3389')->assertSee('Remote Desktop');
        $this->get(route('devices.inventory', ['device' => $device, 'tab' => 'services', 'q' => 'spool']))->assertOk()->assertSee('Spooler');
        $this->get(route('devices.inventory', ['device' => $device, 'tab' => 'services', 'q' => 'tidak-ada']))->assertOk()->assertDontSee('Spooler');
        $this->get(route('devices.inventory', ['device' => $device, 'filter' => 'attention']))->assertOk()->assertSee('3389');
    }

    public function test_winlog_events_are_classified_deduplicated_and_admin_only(): void
    {
        config(['netwatch.winlog.enabled' => true]);
        [$device, $token] = $this->enroll();
        $at = now()->subMinutes(2)->timestamp;
        $payload = ['events' => [
            ['log' => 'System', 'provider' => 'Microsoft-Windows-Kernel-Power', 'id' => 41, 'level' => 1, 'time' => $at, 'record' => 1001, 'message' => 'Sistem dimulai ulang tanpa shutdown bersih.'],
            ['log' => 'System', 'provider' => 'Tcpip', 'id' => 4199, 'level' => 2, 'time' => $at, 'record' => 1002, 'message' => "Alamat IP bentrok\x00", 'count' => 3],
            ['log' => 'Security', 'provider' => 'Microsoft-Windows-Security-Auditing', 'id' => 4625, 'level' => 2, 'time' => $at, 'record' => 1003, 'message' => 'x'],
            ['log' => 'System', 'provider' => 'Tcpip', 'id' => 1, 'level' => 2, 'time' => now()->addDay()->timestamp, 'record' => 1004, 'message' => 'masa depan'],
        ]];

        $this->withToken($token)->postJson('/api/agent/v1/winlog', $payload)->assertCreated()->assertJson(['stored' => 2]);
        $this->withToken($token)->postJson('/api/agent/v1/winlog', $payload)->assertCreated()->assertJson(['stored' => 0]); // kiriman ulang

        $this->assertSame(2, Event::query()->where('code', 'winlog.event')->count());
        $power = Event::query()->where('details->event_id', 41)->firstOrFail();
        $this->assertSame('availability', $power->category->value);
        $this->assertSame('critical', $power->severity->value);
        $this->assertStringContainsString('mati/restart mendadak', $power->hint() ?? '');
        $this->assertDatabaseHas('events', ['code' => 'winlog.event', 'category' => 'connectivity']);
        $this->assertStringContainsString('(×3)', Event::query()->where('details->event_id', 4199)->firstOrFail()->message);

        $this->actingAs($this->user(UserRole::Viewer))->get('/events?preset=24h')->assertOk()->assertDontSee('Kernel-Power');
        $this->actingAs($this->user(UserRole::Admin))->get('/events?preset=24h')->assertOk()->assertSee('Kernel-Power');
    }

    public function test_winlog_disabled_on_server_discards_data_and_heartbeat_reports_flag(): void
    {
        config(['netwatch.winlog.enabled' => false]); // jangan bergantung pada .env lokal
        [, $token] = $this->enroll();

        $this->withToken($token)->postJson('/api/agent/v1/winlog', ['events' => [
            ['log' => 'System', 'provider' => 'Tcpip', 'id' => 4199, 'level' => 2, 'time' => now()->timestamp, 'record' => 1, 'message' => 'x'],
        ]])->assertOk()->assertJson(['stored' => 0]); // dinonaktifkan: dibuang dengan 200
        $this->assertSame(0, Event::query()->where('code', 'winlog.event')->count());

        $this->withToken($token)->postJson('/api/agent/v1/heartbeat', ['metrics' => [
            'cpu_percent' => 5, 'memory_used_mb' => 1000, 'memory_total_mb' => 8000,
            'disk_used_gb' => 50, 'disk_total_gb' => 200, 'uptime_seconds' => 1000,
        ]])->assertOk()->assertJson(['winlog_enabled' => false]);
    }

    // ---------- helper ----------

    /** @return array<string, mixed> */
    private function port(int $port, string $address = '0.0.0.0', ?string $process = null): array
    {
        return ['protocol' => 'tcp', 'address' => $address, 'port' => $port, 'process' => $process];
    }

    /** @return array<string, string> */
    private function service(string $name, string $state): array
    {
        return ['name' => $name, 'display' => $name.' service', 'state' => $state, 'start' => 'Auto'];
    }

    /** @param  list<array<string, mixed>>  $ports @param  list<array<string, mixed>>  $services */
    private function inventory(string $token, array $ports, array $services): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($token)->postJson('/api/agent/v1/inventory', ['ports' => $ports, 'services' => $services]);
    }

    /** @return array{0: Device, 1: string} */
    private function enroll(): array
    {
        $token = app(EnrollmentService::class)->createToken($this->user(UserRole::Admin), 'tes', null, 5, 24)['plain'];
        $response = $this->postJson('/api/agent/v1/enroll', [
            'enrollment_token' => $token, 'hostname' => 'PC-INV-01', 'os_family' => 'windows',
            'os_version' => 'Windows 11 Pro', 'agent_version' => '1.4.0',
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
