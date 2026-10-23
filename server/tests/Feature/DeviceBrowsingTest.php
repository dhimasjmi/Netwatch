<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BrowsingVisit;
use App\Models\Device;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DeviceBrowsingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['netwatch.fleet_cache_seconds' => 0, 'netwatch.browsing.enabled' => true]);
    }

    public function test_device_page_links_to_its_own_browsing_page_and_only_admin_can_open_it(): void
    {
        $a = $this->device('PC-A');
        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)->get("/devices/{$a->public_id}")->assertOk()->assertSee(route('devices.browsing', $a), false);

        $this->actingAs($admin)->get("/devices/{$a->public_id}/browsing")->assertOk()
            ->assertSee('Browsing · PC-A')->assertSee('data-device="'.$a->public_id.'"', false)->assertDontSee('id="mon-device"', false)->assertDontSee('mon-tabs', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'browsing.viewed', 'device_id' => $a->id]);

        foreach ([UserRole::Operator, UserRole::Viewer] as $role) {
            $this->actingAs($this->user($role))->get("/devices/{$a->public_id}/browsing")->assertForbidden();
            $this->actingAs($this->user($role))->getJson("/devices/{$a->public_id}/browsing/data")->assertForbidden();
        }

        $this->actingAs($admin)->get('/monitoring/browsing')->assertOk()->assertSee('id="mon-device"', false)->assertSee('mon-tabs', false);
    }

    public function test_device_data_is_the_same_rows_as_monitoring_but_limited_to_that_device(): void
    {
        $a = $this->device('PC-A');
        $b = $this->device('PC-B');
        $this->visit($a, 'google.com', 1);
        $this->visit($a, 'detik.com', 2);
        $this->visit($b, 'youtube.com', 3);
        $admin = $this->user(UserRole::Admin);

        $fleet = $this->actingAs($admin)->getJson('/monitoring/browsing/data?window=1h')->json();
        $this->assertSame(3, $fleet['table']['total']);

        $one = $this->actingAs($admin)->getJson("/devices/{$a->public_id}/browsing/data?window=1h")->assertOk()->json();
        $this->assertSame(2, $one['table']['total']);
        $this->assertSame(['google.com', 'detik.com'], array_column($one['table']['rows'], 'host'));

        // Parameter device di query tidak bisa membelokkan halaman ke perangkat lain.
        $forced = $this->actingAs($admin)->getJson("/devices/{$a->public_id}/browsing/data?window=1h&device={$b->public_id}")->json();
        $this->assertSame(2, $forced['table']['total']);

        $fleetFiltered = $this->actingAs($admin)->getJson("/monitoring/browsing/data?window=1h&device={$a->public_id}")->json();
        $this->assertSame($one['table']['rows'], $fleetFiltered['table']['rows']);   // satu data, dua tampilan
    }

    public function test_disabled_feature_hides_device_browsing_and_its_button(): void
    {
        $a = $this->device('PC-A');
        $admin = $this->user(UserRole::Admin);
        $this->actingAs($admin)->patch('/features/browsing', ['action' => 'disable']);

        $this->actingAs($admin)->get("/devices/{$a->public_id}/browsing")->assertNotFound();
        $this->actingAs($admin)->get("/devices/{$a->public_id}")->assertOk()->assertDontSee(route('devices.browsing', $a), false);
    }

    private function visit(Device $device, string $host, int $secondsAgo): void
    {
        BrowsingVisit::query()->create([
            'device_id' => $device->id, 'visited_at' => now()->subSeconds($secondsAgo), 'kind' => 'url', 'host' => $host,
            'dedupe_hash' => sha1($device->id.$host.$secondsAgo),
        ]);
    }

    private function device(string $hostname): Device
    {
        $token = app(EnrollmentService::class)->createToken($this->user(UserRole::Admin), 'tes', null, 5, 24)['plain'];
        $response = $this->postJson('/api/agent/v1/enroll', [
            'enrollment_token' => $token, 'hostname' => $hostname, 'os_family' => 'windows', 'os_version' => 'Windows 11', 'agent_version' => '1.3.0',
        ])->assertCreated();

        return Device::query()->where('public_id', $response->json('device_id'))->firstOrFail();
    }

    private function user(UserRole $role): User
    {
        return User::query()->firstOrCreate(['email' => "{$role->value}@example.test"], ['name' => $role->value, 'password' => 'Password-12345', 'role' => $role, 'is_active' => true]);
    }
}
