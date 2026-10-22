<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BlockRule;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Policy;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\PolicyBuilder;
use App\Services\PolicyStatusResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BlockPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Hindari DNS nyata: server dianggap berada di 203.0.113.5.
        config(['app.url' => 'https://netwatch.perusahaan.test', 'netwatch.server_ips' => ['203.0.113.5']]);
    }

    public function test_only_admin_can_manage_policies(): void
    {
        $policy = Policy::query()->create(['name' => 'Medsos']);

        $this->actingAs($this->user(UserRole::Operator))->get('/policies')->assertForbidden();
        $this->actingAs($this->user(UserRole::Operator))->post('/policies', ['name' => 'X', 'scope' => 'all'])->assertForbidden();
        $this->actingAs($this->user(UserRole::Operator))->post("/policies/{$policy->id}/entries", ['entries' => 'a.com'])->assertForbidden();
        $this->actingAs($this->user(UserRole::Admin))->get('/policies')->assertOk()->assertSee('Medsos');
    }

    public function test_entries_are_normalized_audited_and_type_is_detected(): void
    {
        $admin = $this->user(UserRole::Admin);
        $policy = Policy::query()->create(['name' => 'Medsos']);

        $this->actingAs($admin)->post("/policies/{$policy->id}/entries", ['entries' => "https://WWW.Facebook.com/x\n198.51.100.77/24, instagram.com"])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('block_rules', ['policy_id' => $policy->id, 'type' => 'domain', 'value' => 'www.facebook.com']);
        $this->assertDatabaseHas('block_rules', ['policy_id' => $policy->id, 'type' => 'domain', 'value' => 'instagram.com']);
        $this->assertDatabaseHas('block_rules', ['policy_id' => $policy->id, 'type' => 'ip', 'value' => '198.51.100.0/24']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'block_rule.created']);
    }

    public function test_dangerous_entries_are_rejected_but_valid_ones_in_same_submit_are_kept(): void
    {
        $admin = $this->user(UserRole::Admin);
        $policy = Policy::query()->create(['name' => 'Campur']);

        $this->actingAs($admin)->post("/policies/{$policy->id}/entries", ['entries' => implode("\n", [
            'tiktok.com',
            '203.0.113.0/24',            // memuat alamat server
            '203.0.113.5',               // tepat alamat server
            '0.0.0.0/0',                 // seluruh internet
            'perusahaan.test',           // induk dari host server
            'netwatch.perusahaan.test',
            'bukan_domain!',
        ])])->assertSessionHasErrors();

        $this->assertSame(['tiktok.com'], BlockRule::query()->pluck('value')->all());
    }

    public function test_duplicate_entry_in_same_policy_is_rejected_but_allowed_in_another_policy(): void
    {
        $admin = $this->user(UserRole::Admin);
        $a = Policy::query()->create(['name' => 'A']);
        $b = Policy::query()->create(['name' => 'B']);

        $this->actingAs($admin)->post("/policies/{$a->id}/entries", ['entries' => 'tiktok.com'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/policies/{$a->id}/entries", ['entries' => '*.TikTok.com'])->assertSessionHasErrors();
        $this->actingAs($admin)->post("/policies/{$b->id}/entries", ['entries' => 'tiktok.com'])->assertSessionHasNoErrors();

        $this->assertSame(2, BlockRule::query()->count());
    }

    public function test_policy_depends_on_scope_and_enabled_flag(): void
    {
        $group = DeviceGroup::query()->create(['name' => 'Lab 1']);
        $inGroup = $this->device('PC-LAB', $group->id);
        $outside = $this->device('PC-LAIN');

        $this->rule('domain', 'semua.com');
        $this->rule('domain', 'grup.com', groupId: $group->id);
        $this->rule('domain', 'satu.com', deviceId: $outside->id);
        $this->rule('domain', 'mati.com', enabled: false);
        $this->rule('ip', '198.51.100.9');

        $builder = app(PolicyBuilder::class);

        $this->assertSame(['grup.com', 'semua.com'], $builder->forDevice($inGroup)->domains);
        $this->assertSame(['satu.com', 'semua.com'], $builder->forDevice($outside)->domains);
        $this->assertSame(['198.51.100.9'], $builder->forDevice($inGroup)->ips);
    }

    public function test_policy_is_delivered_until_agent_reports_the_hash(): void
    {
        [$device, $token] = $this->enrollDevice();
        $this->rule('domain', 'facebook.com');

        $first = $this->heartbeat($token, ['hash' => null, 'error' => null])->assertOk()->json('policy');
        $this->assertSame(['facebook.com'], $first['domains']);
        $this->assertSame(64, strlen($first['hash']));

        // Agent belum menerapkan: server terus mengirim.
        $this->heartbeat($token, ['hash' => null, 'error' => null])->assertJsonPath('policy.hash', $first['hash']);

        // Setelah dilaporkan diterapkan, tidak dikirim lagi.
        $this->heartbeat($token, ['hash' => $first['hash'], 'error' => null])->assertJsonMissingPath('policy');
        $this->assertSame($first['hash'], $device->refresh()->policy_hash);
        $this->assertDatabaseHas('events', ['code' => 'policy.applied', 'device_id' => $device->id]);
        $this->assertSame('ok', app(PolicyStatusResolver::class)->resolve($device)['state']);
    }

    public function test_removing_all_rules_sends_an_empty_policy_to_clear_the_agent(): void
    {
        [, $token] = $this->enrollDevice();
        $rule = $this->rule('domain', 'facebook.com');
        $applied = $this->heartbeat($token, ['hash' => null, 'error' => null])->json('policy.hash');
        $this->heartbeat($token, ['hash' => $applied, 'error' => null])->assertJsonMissingPath('policy');

        $this->actingAs($this->user(UserRole::Admin))->delete("/policies/{$rule->policy_id}/entries/{$rule->id}")->assertRedirect();

        $cleared = $this->heartbeat($token, ['hash' => $applied, 'error' => null])->assertJsonPath('policy.domains', [])->json('policy.hash');
        $this->assertNotSame($applied, $cleared);
        $this->heartbeat($token, ['hash' => $cleared, 'error' => null])->assertJsonMissingPath('policy');
    }

    public function test_null_hash_is_never_assumed_clean_even_without_rules(): void
    {
        [, $token] = $this->enrollDevice();

        // State agent hilang (reinstal): aturan lama bisa saja masih terpasang, jadi server mengirim kebijakan kosong untuk membersihkan.
        $empty = $this->heartbeat($token, ['hash' => null, 'error' => null])->assertJsonPath('policy.domains', [])->json('policy.hash');

        // Setelah agent membuktikan kondisi bersih (hash kebijakan kosong), tidak dikirim lagi.
        $this->heartbeat($token, ['hash' => $empty, 'error' => null])->assertJsonMissingPath('policy');
    }

    public function test_drift_is_detected_and_policy_is_resent(): void
    {
        [$device, $token] = $this->enrollDevice();
        $this->rule('domain', 'facebook.com');
        $good = $this->heartbeat($token, ['hash' => null, 'error' => null])->json('policy.hash');
        $this->heartbeat($token, ['hash' => $good, 'error' => null])->assertJsonMissingPath('policy');

        // Hosts dihapus manual: agent melaporkan hash lain (menyimpang).
        $drift = hash('sha256', 'drift');
        $this->heartbeat($token, ['hash' => $drift, 'error' => null])->assertJsonPath('policy.hash', $good);
        $this->assertDatabaseHas('events', ['code' => 'policy.drift', 'device_id' => $device->id]);
        $this->assertSame('pending', app(PolicyStatusResolver::class)->resolve($device->refresh())['state']);

        $this->heartbeat($token, ['hash' => $good, 'error' => null])->assertJsonMissingPath('policy');
        $this->assertSame('ok', app(PolicyStatusResolver::class)->resolve($device->refresh())['state']);
    }

    public function test_offline_release_is_logged_as_info_and_policy_is_resent_on_return(): void
    {
        [$device, $token] = $this->enrollDevice();
        $this->rule('domain', 'facebook.com');
        $good = $this->heartbeat($token, ['hash' => null, 'error' => null])->json('policy.hash');
        $this->heartbeat($token, ['hash' => $good, 'error' => null])->assertJsonMissingPath('policy');

        // Agent kembali terhubung setelah di luar kantor: aturan sudah dilepas (hash kosong) dan ditandai.
        $empty = \App\Support\DevicePolicy::fromValues([], [])->hash();
        $this->heartbeat($token, ['hash' => $empty, 'error' => null, 'offline_release' => true])->assertJsonPath('policy.hash', $good);

        $this->assertDatabaseHas('events', ['code' => 'policy.offline_release', 'device_id' => $device->id]);
        $this->assertDatabaseMissing('events', ['code' => 'policy.drift', 'device_id' => $device->id]);
    }

    public function test_domain_removed_from_policy_changes_desired_hash_and_is_resent(): void
    {
        [, $token] = $this->enrollDevice();
        $keep = $this->rule('domain', 'instagram.com');
        $gone = $this->rule('domain', 'facebook.com');
        $both = $this->heartbeat($token, ['hash' => null, 'error' => null])->json('policy.hash');

        $this->actingAs($this->user(UserRole::Admin))->delete("/policies/{$gone->policy_id}/entries/{$gone->id}")->assertRedirect();

        $this->heartbeat($token, ['hash' => $both, 'error' => null])->assertJsonPath('policy.domains', ['instagram.com']);
        $this->assertNotNull($keep);
    }

    public function test_agent_hash_matches_canonical_server_hash(): void
    {
        // Vektor yang sama diuji di agent (tests/run.php): kedua sisi harus menghasilkan hash identik.
        $policy = \App\Support\DevicePolicy::fromValues(['instagram.com', 'facebook.com'], ['203.0.113.0/24']);
        $this->assertSame(hash('sha256', '{"d":["facebook.com","instagram.com"],"i":["203.0.113.0/24"]}'), $policy->hash());
    }

    public function test_nothing_is_sent_to_agents_that_do_not_report_policy(): void
    {
        [, $token] = $this->enrollDevice();

        $this->rule('domain', 'facebook.com');
        $this->heartbeat($token, null)->assertJsonMissingPath('policy'); // agent lama (1.1.0)
    }

    public function test_agent_failure_is_stored_and_raises_one_event(): void
    {
        [$device, $token] = $this->enrollDevice();
        $this->rule('domain', 'facebook.com');

        $this->heartbeat($token, ['hash' => null, 'error' => 'Akses ditolak'])->assertOk();
        $this->heartbeat($token, ['hash' => null, 'error' => 'Akses ditolak'])->assertOk();

        $this->assertSame('Akses ditolak', $device->refresh()->policy_error);
        $this->assertSame(1, \App\Models\Event::query()->where('code', 'policy.failed')->count());
        $this->assertSame('error', app(PolicyStatusResolver::class)->resolve($device)['state']);
    }

    public function test_linux_devices_do_not_receive_policy(): void
    {
        [$device, $token] = $this->enrollDevice();
        $device->forceFill(['os_family' => 'linux'])->save();
        $this->rule('domain', 'facebook.com');

        $this->heartbeat($token, ['hash' => null, 'error' => null])->assertJsonMissingPath('policy');
        $this->assertSame('na', app(PolicyStatusResolver::class)->resolve($device->refresh())['state']);
    }

    public function test_policy_toggle_and_entry_delete_are_audited(): void
    {
        $admin = $this->user(UserRole::Admin);
        $rule = $this->rule('domain', 'facebook.com');

        $this->actingAs($admin)->post("/policies/{$rule->policy_id}/toggle")->assertRedirect();
        $this->assertFalse($rule->policy->refresh()->is_enabled);
        $this->assertDatabaseHas('audit_logs', ['action' => 'policy.disabled']);

        $this->actingAs($admin)->delete("/policies/{$rule->policy_id}/entries/{$rule->id}")->assertRedirect();
        $this->assertDatabaseMissing('block_rules', ['id' => $rule->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'block_rule.deleted']);
    }

    // ---------- helper ----------

    /** Membuat policy sesuai cakupan lalu satu aturan di dalamnya. Policy nonaktif bila $enabled false. */
    private function rule(string $type, string $value, ?int $groupId = null, ?int $deviceId = null, bool $enabled = true): BlockRule
    {
        $policy = Policy::query()->create([
            'name' => 'Policy '.uniqid(), 'is_enabled' => $enabled, 'device_group_id' => $groupId, 'device_id' => $deviceId,
        ]);

        return BlockRule::query()->create(['policy_id' => $policy->id, 'type' => $type, 'value' => $value, 'is_enabled' => true]);
    }

    private function device(string $hostname, ?int $groupId = null): Device
    {
        return Device::query()->create([
            'hostname' => $hostname, 'os_family' => 'windows', 'agent_version' => '1.2.0', 'device_group_id' => $groupId,
            'token_hash' => hash('sha256', $hostname.uniqid('', true)), 'enrolled_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    /** @param  array<string, string|null>|null  $policy */
    private function heartbeat(string $token, ?array $policy): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($token)->postJson('/api/agent/v1/heartbeat', array_filter([
            'metrics' => [
                'cpu_percent' => 5, 'memory_used_mb' => 1000, 'memory_total_mb' => 8000,
                'disk_used_gb' => 50, 'disk_total_gb' => 200, 'uptime_seconds' => 1000,
            ],
            'policy' => $policy,
        ], fn ($v) => $v !== null));
    }

    /** @return array{0: Device, 1: string} */
    private function enrollDevice(): array
    {
        $token = app(EnrollmentService::class)->createToken($this->user(UserRole::Admin), 'tes', null, 5, 24)['plain'];

        $response = $this->postJson('/api/agent/v1/enroll', [
            'enrollment_token' => $token, 'hostname' => 'PC-BLK-01', 'os_family' => 'windows',
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
