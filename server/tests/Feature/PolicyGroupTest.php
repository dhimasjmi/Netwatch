<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BlockRule;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Policy;
use App\Models\User;
use App\Services\PolicyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PolicyGroupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://netwatch.perusahaan.test', 'netwatch.server_ips' => ['203.0.113.5']]);
    }

    public function test_admin_builds_social_media_policy_for_a_group(): void
    {
        $admin = $this->admin();
        $group = DeviceGroup::query()->create(['name' => 'Lab 1']);
        $inGroup = $this->device('PC-LAB', $group->id);
        $outside = $this->device('PC-LAIN');

        $this->actingAs($admin)->post('/policies', ['name' => 'Media sosial', 'description' => 'Jam kerja', 'scope' => 'group:'.$group->id])
            ->assertSessionHasNoErrors();
        $policy = Policy::query()->where('name', 'Media sosial')->firstOrFail();
        $this->post("/policies/{$policy->id}/entries", ['entries' => "facebook.com\ninstagram.com"])->assertSessionHasNoErrors();

        $builder = app(PolicyBuilder::class);
        $this->assertSame(['facebook.com', 'instagram.com'], $builder->forDevice($inGroup)->domains);
        $this->assertSame([], $builder->forDevice($outside)->domains);
        $this->assertDatabaseHas('audit_logs', ['action' => 'policy.created']);
    }

    public function test_disabling_a_policy_removes_all_of_its_targets_and_enabling_restores_them(): void
    {
        $device = $this->device('PC-1');
        $policy = Policy::query()->create(['name' => 'Medsos']);
        foreach (['facebook.com', 'instagram.com'] as $d) {
            BlockRule::query()->create(['policy_id' => $policy->id, 'type' => 'domain', 'value' => $d]);
        }
        $builder = app(PolicyBuilder::class);

        $this->actingAs($this->admin())->post("/policies/{$policy->id}/toggle");
        $this->assertSame([], $builder->forDevice($device)->domains);

        $this->post("/policies/{$policy->id}/toggle");
        $this->assertSame(['facebook.com', 'instagram.com'], $builder->forDevice($device)->domains);
    }

    public function test_same_target_in_two_active_policies_is_sent_once(): void
    {
        $device = $this->device('PC-1');
        foreach (['A', 'B'] as $name) {
            $p = Policy::query()->create(['name' => $name]);
            BlockRule::query()->create(['policy_id' => $p->id, 'type' => 'domain', 'value' => 'tiktok.com']);
        }

        $this->assertSame(['tiktok.com'], app(PolicyBuilder::class)->forDevice($device)->domains);
    }

    public function test_policy_name_must_be_unique_and_can_be_kept_on_update(): void
    {
        $admin = $this->admin();
        $a = Policy::query()->create(['name' => 'Medsos']);
        Policy::query()->create(['name' => 'Streaming']);

        $this->actingAs($admin)->post('/policies', ['name' => 'Medsos', 'scope' => 'all'])->assertSessionHasErrors('name');
        $this->patch("/policies/{$a->id}", ['name' => 'Streaming', 'scope' => 'all'])->assertSessionHasErrors('name');
        $this->patch("/policies/{$a->id}", ['name' => 'Medsos', 'description' => 'baru', 'scope' => 'all'])->assertSessionHasNoErrors();
        $this->assertSame('baru', $a->refresh()->description);
    }

    public function test_invalid_scope_is_rejected(): void
    {
        $this->actingAs($this->admin())->post('/policies', ['name' => 'X', 'scope' => 'group:999999'])->assertSessionHasErrors('scope');
        $this->post('/policies', ['name' => 'X', 'scope' => 'semua'])->assertSessionHasErrors('scope');
    }

    public function test_deleting_policy_removes_its_rules_and_cannot_remove_entry_of_other_policy(): void
    {
        $admin = $this->admin();
        $a = Policy::query()->create(['name' => 'A']);
        $b = Policy::query()->create(['name' => 'B']);
        $ruleA = BlockRule::query()->create(['policy_id' => $a->id, 'type' => 'domain', 'value' => 'a.com']);
        BlockRule::query()->create(['policy_id' => $b->id, 'type' => 'domain', 'value' => 'b.com']);

        $this->actingAs($admin)->delete("/policies/{$b->id}/entries/{$ruleA->id}")->assertNotFound();
        $this->delete("/policies/{$a->id}")->assertRedirect(route('policies.index'));

        $this->assertSame(['b.com'], BlockRule::query()->pluck('value')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'policy.deleted']);
    }

    private function admin(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'admin@example.test'],
            ['name' => 'Admin', 'password' => 'Password-12345', 'role' => UserRole::Admin, 'is_active' => true],
        );
    }

    private function device(string $hostname, ?int $groupId = null): Device
    {
        return Device::query()->create([
            'hostname' => $hostname, 'os_family' => 'windows', 'agent_version' => '1.2.0', 'device_group_id' => $groupId,
            'token_hash' => hash('sha256', $hostname.uniqid('', true)), 'enrolled_at' => now(), 'last_seen_at' => now(),
        ]);
    }
}
