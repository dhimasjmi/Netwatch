<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BrowsingVisit;
use App\Models\Device;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\RetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BrowsingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['netwatch.browsing.enabled' => true, 'netwatch.browsing.detail' => 'domain']);
    }

    public function test_agent_batch_is_stored_sanitized_and_resend_is_idempotent(): void
    {
        [$device, $token] = $this->enroll();
        $payload = ['visits' => [
            ['kind' => 'url', 'at' => now()->subMinute()->toIso8601String(), 'url' => 'https://example.com/cari?q=rahasia', 'browser' => 'edge'],
            ['kind' => 'connection', 'at' => now()->subMinute()->toIso8601String(), 'ip' => '93.184.216.34', 'port' => 443, 'host' => 'example.com', 'process' => 'chrome.exe'],
            ['kind' => 'url', 'at' => now()->toIso8601String(), 'url' => 'ftp://example.com/'],
        ]];

        $this->withToken($token)->postJson('/api/agent/v1/browsing', $payload)->assertCreated()->assertJson(['stored' => 2, 'rejected' => 1]);
        $this->withToken($token)->postJson('/api/agent/v1/browsing', $payload)->assertCreated()->assertJson(['stored' => 0]);

        $this->assertSame(2, BrowsingVisit::query()->where('device_id', $device->id)->count());
        $this->assertDatabaseMissing('browsing_visits', ['host' => 'example.com', 'url' => 'https://example.com/cari?q=rahasia']);
        $this->assertSame(0, BrowsingVisit::query()->whereNotNull('url')->count());
    }

    public function test_disabled_on_server_discards_data(): void
    {
        config(['netwatch.browsing.enabled' => false]);
        [, $token] = $this->enroll();

        $this->withToken($token)->postJson('/api/agent/v1/browsing', ['visits' => [
            ['kind' => 'connection', 'at' => now()->toIso8601String(), 'ip' => '93.184.216.34'],
        ]])->assertOk()->assertJson(['stored' => 0]);

        $this->assertSame(0, BrowsingVisit::query()->count());
    }

    public function test_heartbeat_tells_agent_whether_collection_is_enabled(): void
    {
        [, $token] = $this->enroll();

        $this->withToken($token)->postJson('/api/agent/v1/heartbeat', ['metrics' => [
            'cpu_percent' => 5, 'memory_used_mb' => 1000, 'memory_total_mb' => 8000,
            'disk_used_gb' => 50, 'disk_total_gb' => 200, 'uptime_seconds' => 1000,
        ]])->assertOk()->assertJson(['browsing_enabled' => true]);
    }

    public function test_oversized_batch_is_rejected(): void
    {
        [, $token] = $this->enroll();
        $visits = array_fill(0, 501, ['kind' => 'connection', 'at' => now()->toIso8601String(), 'ip' => '93.184.216.34']);

        $this->withToken($token)->postJson('/api/agent/v1/browsing', ['visits' => $visits])->assertUnprocessable();
    }

    public function test_only_admin_can_view_and_access_is_audited(): void
    {
        [$device] = $this->enroll();
        BrowsingVisit::query()->create([
            'device_id' => $device->id, 'visited_at' => now()->subMinutes(5), 'kind' => 'url', 'host' => 'contoh.test',
            'dedupe_hash' => sha1('a'),
        ]);

        $this->actingAs($this->user(UserRole::Viewer))->get('/browsing')->assertForbidden();

        $this->actingAs($this->user(UserRole::Admin))->get('/browsing')->assertOk()->assertSee('contoh.test');
        $this->assertDatabaseHas('audit_logs', ['action' => 'browsing.viewed']);
        $this->get('/browsing?q=tidak-ada')->assertOk()->assertDontSee('contoh.test');
        $this->get('/browsing?q=%25')->assertOk()->assertDontSee('contoh.test'); // "%" dicari harfiah, bukan wildcard
    }

    public function test_top_and_csv_export(): void
    {
        [$device] = $this->enroll();
        foreach (['a', 'b', 'c'] as $i => $n) {
            BrowsingVisit::query()->create([
                'device_id' => $device->id, 'visited_at' => now()->subMinutes($i + 1), 'kind' => 'connection',
                'host' => $n === 'c' ? null : 'ramai.test', 'ip' => '10.0.0.'.$i, 'dedupe_hash' => sha1($n),
            ]);
        }
        BrowsingVisit::query()->create([
            'device_id' => $device->id, 'visited_at' => now()->subMinutes(9), 'kind' => 'url', 'host' => '=cmd.test', 'dedupe_hash' => sha1('z'),
        ]);

        $admin = $this->user(UserRole::Admin);
        $this->actingAs($admin)->get('/browsing')->assertOk()->assertSeeInOrder(['ramai.test', '2']);

        $csv = $this->actingAs($admin)->get('/browsing/csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('ramai.test', $csv);
        $this->assertStringContainsString("'=cmd.test", $csv); // anti injeksi rumus
        $this->assertDatabaseHas('audit_logs', ['action' => 'browsing.exported']);
    }

    public function test_retention_removes_old_visits_only(): void
    {
        [$device] = $this->enroll();
        BrowsingVisit::query()->create(['device_id' => $device->id, 'visited_at' => now()->subDays(40), 'kind' => 'url', 'host' => 'lama.test', 'dedupe_hash' => sha1('old')]);
        BrowsingVisit::query()->create(['device_id' => $device->id, 'visited_at' => now()->subDays(1), 'kind' => 'url', 'host' => 'baru.test', 'dedupe_hash' => sha1('new')]);

        app(RetentionService::class)->prune();

        $this->assertSame(['baru.test'], BrowsingVisit::query()->pluck('host')->all());
    }

    /** @return array{0: Device, 1: string} */
    private function enroll(): array
    {
        $token = app(EnrollmentService::class)->createToken($this->user(UserRole::Admin), 'tes', null, 5, 24)['plain'];
        $response = $this->postJson('/api/agent/v1/enroll', [
            'enrollment_token' => $token, 'hostname' => 'PC-BRW-01', 'os_family' => 'windows',
            'os_version' => 'Windows 11 Pro', 'agent_version' => '1.3.0',
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
