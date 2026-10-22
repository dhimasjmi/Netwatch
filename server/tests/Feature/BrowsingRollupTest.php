<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BrowsingDaily;
use App\Models\BrowsingIgnoredHost;
use App\Models\BrowsingVisit;
use App\Models\Device;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\RetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BrowsingRollupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['netwatch.browsing.enabled' => true, 'netwatch.browsing.detail' => 'domain', 'netwatch.retention.browsing_days' => 14]);
    }

    public function test_old_raw_rows_are_rolled_up_per_day_and_deleted(): void
    {
        [$device] = $this->enroll();
        $day = now()->subDays(20)->setTime(10, 0);
        $this->visit($device, 'situs.test', $day, 'a1');
        $this->visit($device, 'situs.test', $day->copy()->addHour(), 'a2');
        $this->visit($device, 'situs.test', $day->copy()->addHours(2), 'a3');
        $this->visit($device, 'lain.test', $day, 'a4');
        $this->visit($device, 'situs.test', now()->subDay(), 'new'); // masih dalam retensi mentah

        $result = app(RetentionService::class)->prune();

        $this->assertSame(4, $result['browsing']);
        $this->assertSame([sha1('new')], BrowsingVisit::query()->pluck('dedupe_hash')->all());
        $this->assertSame(3, (int) BrowsingDaily::query()->where('host', 'situs.test')->sole()->visits);
        $this->assertSame(1, (int) BrowsingDaily::query()->where('host', 'lain.test')->sole()->visits);
    }

    public function test_rollup_is_idempotent_and_adds_to_existing_day(): void
    {
        [$device] = $this->enroll();
        $day = now()->subDays(20)->setTime(10, 0);
        $this->visit($device, 'situs.test', $day, 'b1');
        app(RetentionService::class)->prune();
        app(RetentionService::class)->prune(); // tanpa data baru: tidak mengubah apa pun
        $this->assertSame(1, (int) BrowsingDaily::query()->sole()->visits);

        $this->visit($device, 'situs.test', $day->copy()->addHour(), 'b2'); // kiriman terlambat pada hari yang sama
        app(RetentionService::class)->prune();

        $this->assertSame(2, (int) BrowsingDaily::query()->sole()->visits);
    }

    public function test_connection_ip_kept_only_when_no_hostname(): void
    {
        [$device] = $this->enroll();
        $day = now()->subDays(20);
        BrowsingVisit::query()->create(['device_id' => $device->id, 'visited_at' => $day, 'kind' => 'connection', 'host' => 'cdn.test', 'ip' => '10.0.0.1', 'dedupe_hash' => sha1('c1')]);
        BrowsingVisit::query()->create(['device_id' => $device->id, 'visited_at' => $day, 'kind' => 'connection', 'host' => 'cdn.test', 'ip' => '10.0.0.2', 'dedupe_hash' => sha1('c2')]);
        BrowsingVisit::query()->create(['device_id' => $device->id, 'visited_at' => $day, 'kind' => 'connection', 'host' => null, 'ip' => '10.9.9.9', 'dedupe_hash' => sha1('c3')]);

        app(RetentionService::class)->prune();

        $this->assertSame(2, BrowsingDaily::query()->count());
        $this->assertSame(2, (int) BrowsingDaily::query()->where('host', 'cdn.test')->sole()->visits); // dua IP berbeda jadi satu baris
        $this->assertSame('10.9.9.9', BrowsingDaily::query()->where('host', '')->sole()->ip);
    }

    public function test_old_summaries_are_pruned_after_daily_retention(): void
    {
        [$device] = $this->enroll();
        config(['netwatch.retention.browsing_daily_days' => 365]);
        foreach ([400, 10] as $i => $ago) {
            BrowsingDaily::query()->create(['device_id' => $device->id, 'day' => now()->subDays($ago)->toDateString(), 'kind' => 'url', 'host' => "h{$i}.test",
                'ip' => '', 'visits' => 5, 'first_at' => now()->subDays($ago), 'last_at' => now()->subDays($ago)]);
        }

        $this->assertSame(1, app(RetentionService::class)->prune()['browsing_daily']);
        $this->assertSame(['h1.test'], BrowsingDaily::query()->pluck('host')->all());
    }

    public function test_report_combines_raw_and_daily_and_hides_detail_without_filter(): void
    {
        [$device] = $this->enroll();
        BrowsingDaily::query()->create(['device_id' => $device->id, 'day' => now()->subDays(20)->toDateString(), 'kind' => 'url', 'host' => 'gabungan.test',
            'ip' => '', 'visits' => 7, 'first_at' => now()->subDays(20), 'last_at' => now()->subDays(20)]);
        $this->visit($device, 'gabungan.test', now()->subMinutes(5), 'g1');
        $this->visit($device, 'gabungan.test', now()->subMinutes(6), 'g2');

        $admin = $this->user(UserRole::Admin);
        $this->actingAs($admin)->get('/browsing?preset=30d')->assertOk()
            ->assertSeeInOrder(['gabungan.test', '9'])
            ->assertSee('Pilih perangkat atau ketik kata pencarian');
        $this->get('/browsing?preset=30d&device='.$device->public_id)->assertOk()->assertDontSee('Pilih perangkat atau ketik kata pencarian');
        $this->get('/browsing?preset=30d&q=gabungan')->assertOk()->assertSeeInOrder(['gabungan.test', '9']);
    }

    public function test_ignored_domains_and_subdomains_are_not_stored(): void
    {
        [, $token] = $this->enroll();
        $at = now()->subMinute()->toIso8601String();

        $this->withToken($token)->postJson('/api/agent/v1/browsing', ['visits' => [
            ['kind' => 'url', 'at' => $at, 'url' => 'https://download.windowsupdate.com/x'],
            ['kind' => 'connection', 'at' => $at, 'ip' => '1.2.3.4', 'host' => 'ocsp.digicert.com'],
            ['kind' => 'url', 'at' => $at, 'url' => 'https://contoh.test/'],
            ['kind' => 'connection', 'at' => $at, 'ip' => '5.6.7.8'], // tanpa nama: tidak bisa disaring
        ]])->assertCreated()->assertJson(['stored' => 2, 'ignored' => 2, 'rejected' => 0]);

        $this->assertEqualsCanonicalizing(['contoh.test', null], BrowsingVisit::query()->pluck('host')->all());
    }

    public function test_per_device_daily_cap_drops_excess(): void
    {
        config(['netwatch.browsing.max_per_device_day' => 2]);
        [, $token] = $this->enroll();
        $at = now()->subMinute()->toIso8601String();
        $visits = array_map(fn (int $i) => ['kind' => 'url', 'at' => $at, 'url' => "https://s{$i}.test/"], [1, 2, 3]);

        $this->withToken($token)->postJson('/api/agent/v1/browsing', ['visits' => $visits])->assertCreated()->assertJson(['stored' => 2, 'over_limit' => 1]);
        $this->withToken($token)->postJson('/api/agent/v1/browsing', ['visits' => [['kind' => 'url', 'at' => $at, 'url' => 'https://s9.test/']]])
            ->assertCreated()->assertJson(['stored' => 0, 'over_limit' => 1]);
    }

    public function test_only_admin_manages_ignore_list_with_audit(): void
    {
        $this->actingAs($this->user(UserRole::Viewer))->post(route('browsing.ignored.store'), ['domain' => 'x.test'])->assertForbidden();

        $this->actingAs($this->user(UserRole::Admin))->post(route('browsing.ignored.store'), ['domain' => 'https://*.Telemetri.test/', 'note' => 'tes'])->assertRedirect();
        $row = BrowsingIgnoredHost::query()->where('domain', 'telemetri.test')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', ['action' => 'browsing.ignore_added']);

        $this->post(route('browsing.ignored.store'), ['domain' => 'bukan domain'])->assertSessionHasErrors('domain');

        $this->delete(route('browsing.ignored.destroy', $row))->assertRedirect();
        $this->assertDatabaseMissing('browsing_ignored_hosts', ['domain' => 'telemetri.test']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'browsing.ignore_removed']);
    }

    private function visit(Device $device, string $host, \DateTimeInterface $at, string $hash): void
    {
        BrowsingVisit::query()->create(['device_id' => $device->id, 'visited_at' => $at, 'kind' => 'url', 'host' => $host, 'dedupe_hash' => sha1($hash)]);
    }

    /** @return array{0: Device, 1: string} */
    private function enroll(): array
    {
        $token = app(EnrollmentService::class)->createToken($this->user(UserRole::Admin), 'tes', null, 5, 24)['plain'];
        $response = $this->postJson('/api/agent/v1/enroll', [
            'enrollment_token' => $token, 'hostname' => 'PC-RLP-01', 'os_family' => 'windows',
            'os_version' => 'Windows 11 Pro', 'agent_version' => '1.6.0',
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
