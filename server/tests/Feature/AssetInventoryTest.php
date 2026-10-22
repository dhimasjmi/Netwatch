<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Device;
use App\Models\DeviceHardware;
use App\Models\DeviceSoftware;
use App\Models\Event;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class AssetInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_report_stores_hardware_and_software_without_events(): void
    {
        [$device, $token] = $this->enroll();

        $this->assets($token, $this->hardware(), [$this->software('Chrome', '120'), $this->software('7-Zip', '23')])
            ->assertCreated()->assertJson(['software' => 2, 'hardware_changed' => false]);

        $this->assertSame(2, DeviceSoftware::query()->where('device_id', $device->id)->count());
        $this->assertSame('Dell', DeviceHardware::query()->where('device_id', $device->id)->sole()->manufacturer);
        $this->assertNotNull($device->refresh()->assets_reported_at);
        $this->assertSame(0, Event::query()->whereIn('code', ['software.installed', 'software.removed'])->count()); // baseline
    }

    public function test_uninstalled_software_disappears_on_next_report_and_is_logged(): void
    {
        [$device, $token] = $this->enroll();
        $this->assets($token, $this->hardware(), [$this->software('Chrome', '120'), $this->software('7-Zip', '23')])->assertCreated();
        $first = DeviceSoftware::query()->where('name', 'Chrome')->sole()->first_seen_at;

        $this->travel(5)->minutes();
        $this->assets($token, $this->hardware(), [$this->software('Chrome', '120')])->assertCreated();

        $this->assertSame(['Chrome'], DeviceSoftware::query()->where('device_id', $device->id)->pluck('name')->all());
        $this->assertSame($first->timestamp, DeviceSoftware::query()->where('name', 'Chrome')->sole()->first_seen_at->timestamp);
        $event = Event::query()->where('code', 'software.removed')->sole();
        $this->assertSame('7-Zip', $event->details['name']);
        $this->assertSame($device->id, $event->device_id);
    }

    public function test_new_software_is_logged_but_version_upgrade_is_not(): void
    {
        [, $token] = $this->enroll();
        $this->assets($token, $this->hardware(), [$this->software('Chrome', '120')])->assertCreated();

        $this->assets($token, $this->hardware(), [$this->software('Chrome', '121'), $this->software('TeamViewer', '15')])->assertCreated();

        $this->assertSame(['Chrome' => '121', 'TeamViewer' => '15'], DeviceSoftware::query()->orderBy('name')->pluck('version', 'name')->all());
        $this->assertSame(['TeamViewer'], Event::query()->where('code', 'software.installed')->get()->map(fn (Event $e) => $e->details['name'])->all());
        $this->assertSame(0, Event::query()->where('code', 'software.removed')->count());
    }

    public function test_null_software_keeps_existing_data(): void
    {
        [$device, $token] = $this->enroll();
        $this->assets($token, $this->hardware(), [$this->software('Chrome', '120')])->assertCreated();

        $this->assets($token, null, null)->assertCreated();

        $this->assertSame(1, DeviceSoftware::query()->where('device_id', $device->id)->count());
        $this->assertNotNull(DeviceHardware::query()->where('device_id', $device->id)->first());
        $this->assertSame(0, Event::query()->where('code', 'software.removed')->count());
    }

    public function test_hardware_change_after_baseline_is_logged(): void
    {
        [, $token] = $this->enroll();
        $this->assets($token, $this->hardware(), null)->assertCreated();

        $this->assets($token, ['memory_mb' => 32768] + $this->hardware(), null)->assertCreated()->assertJson(['hardware_changed' => true]);

        $event = Event::query()->where('code', 'hardware.changed')->sole();
        $this->assertSame(['RAM'], $event->details['parts']);
        $this->assertSame('warning', $event->severity->value);
    }

    public function test_same_report_twice_is_idempotent(): void
    {
        [$device, $token] = $this->enroll();
        $rows = [$this->software('Chrome', '120'), $this->software('Chrome', '')];

        $this->assets($token, $this->hardware(), $rows)->assertCreated();
        $this->assets($token, $this->hardware(), $rows)->assertCreated();

        $this->assertSame(2, DeviceSoftware::query()->where('device_id', $device->id)->count());
        $this->assertSame(0, Event::query()->whereIn('code', ['software.installed', 'software.removed', 'hardware.changed'])->count());
    }

    public function test_invalid_payload_is_rejected_and_requires_agent_token(): void
    {
        [, $token] = $this->enroll();

        $this->withToken($token)->postJson('/api/agent/v1/assets', ['software' => [['name' => '']], 'hardware' => null])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/agent/v1/assets', ['hardware' => null])->assertUnprocessable(); // field wajib hadir
        $this->flushHeaders()->postJson('/api/agent/v1/assets', ['hardware' => null, 'software' => null])->assertUnauthorized(); // withToken menempel di instance tes
    }

    public function test_pages_are_admin_only_and_searchable(): void
    {
        [$device, $token] = $this->enroll();
        $this->assets($token, $this->hardware(), [$this->software('Google Chrome', '120'), $this->software('TeamViewer', '15')])->assertCreated();

        $viewer = $this->user(UserRole::Viewer);
        $this->actingAs($viewer)->get(route('devices.assets', $device))->assertForbidden();
        $this->get(route('assets.index'))->assertForbidden();

        $this->actingAs($this->user(UserRole::Admin))->get(route('devices.assets', $device))->assertOk()->assertSee('Google Chrome')->assertSee('OptiPlex');
        $this->get(route('devices.assets', ['device' => $device, 'q' => 'teamv']))->assertOk()->assertSee('TeamViewer')->assertDontSee('Google Chrome');
        $this->get(route('assets.index'))->assertOk()->assertSee('OptiPlex')->assertSee('PC-AST-01');
        $this->get(route('assets.index', ['tab' => 'software', 'q' => 'team']))->assertOk()->assertSee('TeamViewer')->assertDontSee('Google Chrome');
        $this->get(route('assets.software', ['name' => 'TeamViewer']))->assertOk()->assertSee('PC-AST-01');
        $this->get(route('assets.software'))->assertNotFound();
    }

    /** @param  array<string, mixed>|null  $hardware  @param  list<array<string, mixed>>|null  $software */
    private function assets(string $token, ?array $hardware, ?array $software): TestResponse
    {
        return $this->withToken($token)->postJson('/api/agent/v1/assets', ['hardware' => $hardware, 'software' => $software]);
    }

    /** @return array<string, mixed> */
    private function hardware(): array
    {
        return ['manufacturer' => 'Dell', 'model' => 'OptiPlex 7090', 'serial' => 'ABC123', 'cpu' => 'Intel i5', 'cpu_cores' => 6, 'cpu_threads' => 12,
            'memory_mb' => 16384, 'os' => 'Windows 11 Pro', 'os_version' => '10.0', 'os_build' => '22631', 'disks' => [['model' => 'NVMe', 'size_gb' => 512]]];
    }

    /** @return array<string, mixed> */
    private function software(string $name, string $version): array
    {
        return ['name' => $name, 'version' => $version, 'publisher' => 'Vendor', 'installed' => '2026-01-15'];
    }

    private function user(UserRole $role): User
    {
        return User::query()->firstOrCreate(
            ['email' => "{$role->value}@example.test"],
            ['name' => $role->label(), 'password' => 'Password-12345', 'role' => $role, 'is_active' => true],
        );
    }

    /** @return array{0: Device, 1: string} */
    private function enroll(): array
    {
        $token = app(EnrollmentService::class)->createToken($this->user(UserRole::Admin), 'tes', null, 5, 24)['plain'];
        $response = $this->postJson('/api/agent/v1/enroll', [
            'enrollment_token' => $token, 'hostname' => 'PC-AST-01', 'os_family' => 'windows',
            'os_version' => 'Windows 11 Pro', 'agent_version' => '1.6.0',
        ])->assertCreated();

        return [Device::query()->where('public_id', $response->json('device_id'))->firstOrFail(), $response->json('api_token')];
    }
}
