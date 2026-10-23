<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Device;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FleetBandwidthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['netwatch.fleet_cache_seconds' => 0]);
        $this->travelTo(now()->startOfMinute());
    }

    public function test_aggregates_all_devices_and_paginates_and_sorts(): void
    {
        [$big, $small] = $this->twoDevices();
        $json = $this->actingAs($this->viewer())->getJson('/monitoring/bandwidth/data?window=1h&per_page=5')->assertOk()->json();

        $this->assertSame(2, $json['kpi']['devices_total']);
        $this->assertSame(2, $json['kpi']['devices_reporting']);
        $this->assertSame(30_000 + 3_000, $json['kpi']['total_rx_bytes']);
        $this->assertSame(2, $json['table']['total']);
        $this->assertSame($big->public_id, $json['table']['rows'][0]['id']);   // urut total terbesar lebih dulu
        $this->assertSame(100, $json['table']['rows'][0]['usage_pct']);

        $asc = $this->actingAs($this->viewer())->getJson('/monitoring/bandwidth/data?window=1h&sort=name&dir=asc')->json();
        $this->assertSame('PC-BIG', $asc['table']['rows'][0]['name']);

        $page2 = $this->actingAs($this->viewer())->getJson('/monitoring/bandwidth/data?window=1h&per_page=5&page=2')->json();
        $this->assertSame([], $page2['table']['rows']);
        $this->assertSame(2, $page2['table']['total']);
    }

    public function test_search_only_filters_table_and_selection_limits_trend_and_totals(): void
    {
        [$big, $small] = $this->twoDevices();
        $viewer = $this->viewer();

        $found = $this->actingAs($viewer)->getJson('/monitoring/bandwidth/data?window=1h&q=SMALL')->json();
        $this->assertCount(1, $found['table']['rows']);
        $this->assertSame($small->public_id, $found['table']['rows'][0]['id']);
        $this->assertSame(2, $found['kpi']['devices_total']);

        $picked = $this->actingAs($viewer)->getJson('/monitoring/bandwidth/data?window=1h&only=1&devices[]='.$small->public_id)->json();
        $this->assertSame(1, $picked['selected_count']);
        $this->assertCount(1, $picked['table']['rows']);
        $this->assertSame(3_000, $picked['kpi']['total_rx_bytes']);
    }

    public function test_invalid_input_is_rejected_and_page_requires_login(): void
    {
        $this->getJson('/monitoring/bandwidth/data')->assertUnauthorized();
        $viewer = $this->viewer();
        foreach (['window=3y', 'per_page=7', 'sort=password', 'dir=up', 'page=0', 'group=999', 'devices[]=bukan-uuid'] as $query) {
            $this->actingAs($viewer)->getJson('/monitoring/bandwidth/data?'.$query)->assertUnprocessable();
        }
        $this->actingAs($viewer)->get('/monitoring/bandwidth')->assertOk()->assertSee('Bandwidth Monitoring');
    }

    /** @return array{0: Device, 1: Device} */
    private function twoDevices(): array
    {
        $big = $this->enrollWithTraffic('PC-BIG', 30_000, 3_000);
        $small = $this->enrollWithTraffic('PC-SMALL', 3_000, 300);

        return [$big, $small];
    }

    private function enrollWithTraffic(string $hostname, int $rx, int $tx): Device
    {
        $admin = User::query()->firstOrCreate(['email' => 'enroller@example.test'], ['name' => 'E', 'password' => 'Password-12345', 'role' => UserRole::Admin, 'is_active' => true]);
        $token = app(EnrollmentService::class)->createToken($admin, 'tes', null, 5, 24)['plain'];
        $response = $this->postJson('/api/agent/v1/enroll', [
            'enrollment_token' => $token, 'hostname' => $hostname, 'os_family' => 'windows', 'os_version' => 'Windows 11', 'agent_version' => '1.1.0',
        ])->assertCreated();
        $agent = $response->json('api_token');
        $metrics = ['cpu_percent' => 5, 'memory_used_mb' => 1, 'memory_total_mb' => 8, 'disk_used_gb' => 1, 'disk_total_gb' => 2, 'uptime_seconds' => 1];

        $send = fn (int $r, int $t) => $this->withToken($agent)->postJson('/api/agent/v1/heartbeat', [
            'metrics' => $metrics, 'interfaces' => [['name' => 'Ethernet', 'rx_bytes' => $r, 'tx_bytes' => $t, 'speed_bps' => 1_000_000_000]],
        ])->assertOk();
        $send(0, 0);
        $this->travel(30)->seconds();
        $send($rx, $tx);

        return Device::query()->where('public_id', $response->json('device_id'))->firstOrFail();
    }

    private function viewer(): User
    {
        return User::query()->firstOrCreate(['email' => 'v@example.test'], ['name' => 'V', 'password' => 'Password-12345', 'role' => UserRole::Viewer, 'is_active' => true]);
    }
}
