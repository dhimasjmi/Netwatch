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

final class BrowsingMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['netwatch.fleet_cache_seconds' => 0, 'netwatch.browsing.enabled' => true]);
    }

    public function test_only_admin_can_open_page_and_data(): void
    {
        foreach ([UserRole::Viewer, UserRole::Operator] as $role) {
            $this->actingAs($this->user($role))->get('/monitoring/browsing')->assertForbidden();
            $this->actingAs($this->user($role))->getJson('/monitoring/browsing/data')->assertForbidden();
        }
        $this->actingAs($this->user(UserRole::Admin))->get('/monitoring/browsing')->assertOk()->assertSee('Browsing Monitoring');
        $this->assertDatabaseHas('audit_logs', ['action' => 'browsing.viewed']);
    }

    public function test_kpi_top_sites_and_table_with_filters_and_pagination(): void
    {
        $a = $this->device('PC-A');
        $b = $this->device('PC-B');
        foreach (range(1, 3) as $i) { $this->visit($a, 'google.com', 'url', $i); }
        $this->visit($a, 'youtube.com', 'url', 10);
        $this->visit($b, 'google.com', 'connection', 11);
        $this->visit($b, 'detik.com', 'url', 12);

        $admin = $this->user(UserRole::Admin);
        $json = $this->actingAs($admin)->getJson('/monitoring/browsing/data?window=1h&per_page=10')->assertOk()->json();

        $this->assertSame(6, $json['kpi']['total']);
        $this->assertSame(3, $json['kpi']['unique_sites']);
        $this->assertSame(2, $json['kpi']['devices_active']);
        $this->assertSame('google.com', $json['kpi']['top_site']);
        $this->assertSame('google.com', $json['top_sites'][0]['host']);
        $this->assertEqualsWithDelta(66.7, $json['top_sites'][0]['share_pct'], 0.1);
        $this->assertSame(6, $json['table']['total']);
        $this->assertSame(1, $json['table']['last_page']);

        $paged = $this->actingAs($admin)->getJson('/monitoring/browsing/data?window=1h&per_page=10&kind=connection')->json();
        $this->assertSame(1, $paged['table']['total']);

        $byDevice = $this->actingAs($admin)->getJson('/monitoring/browsing/data?window=1h&device='.$b->public_id)->json();
        $this->assertSame(2, $byDevice['table']['total']);

        $search = $this->actingAs($admin)->getJson('/monitoring/browsing/data?window=1h&q=youtube')->json();
        $this->assertSame(1, $search['table']['total']);
        $this->assertSame('youtube.com', $search['table']['rows'][0]['host']);
    }

    public function test_old_visits_outside_window_are_excluded_and_input_is_validated(): void
    {
        $a = $this->device('PC-A');
        $this->visit($a, 'lama.com', 'url', 60 * 60 * 3);

        $admin = $this->user(UserRole::Admin);
        $this->assertSame(0, $this->actingAs($admin)->getJson('/monitoring/browsing/data?window=1h')->json('table.total'));
        $this->assertSame(1, $this->actingAs($admin)->getJson('/monitoring/browsing/data?window=6h')->json('table.total'));

        foreach (['window=1y', 'per_page=3', 'kind=lain', 'device=bukan-uuid', 'page=0'] as $query) {
            $this->actingAs($admin)->getJson('/monitoring/browsing/data?'.$query)->assertUnprocessable();
        }
    }

    public function test_disabled_feature_returns_404(): void
    {
        $admin = $this->user(UserRole::Admin);
        $this->actingAs($admin)->patch('/features/browsing', ['action' => 'disable']);
        $this->actingAs($admin)->get('/monitoring/browsing')->assertNotFound();
        $this->actingAs($admin)->getJson('/monitoring/browsing/data')->assertNotFound();
    }

    private function visit(Device $device, string $host, string $kind, int $secondsAgo): void
    {
        BrowsingVisit::query()->create([
            'device_id' => $device->id, 'visited_at' => now()->subSeconds($secondsAgo), 'kind' => $kind, 'host' => $host,
            'ip' => $kind === 'connection' ? '93.184.216.34' : null, 'port' => $kind === 'connection' ? 443 : null,
            'dedupe_hash' => sha1($device->id.$host.$kind.$secondsAgo),
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
