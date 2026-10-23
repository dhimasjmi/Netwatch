<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Feature;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\FeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FeatureFlagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_open_and_change_features(): void
    {
        $this->actingAs($this->user(UserRole::Operator))->get('/features')->assertForbidden();
        $this->actingAs($this->user(UserRole::Operator))->patch('/features/bandwidth', ['action' => 'disable'])->assertForbidden();

        $this->actingAs($this->user(UserRole::Admin))->get('/features')->assertOk()->assertSee('Bandwidth');
    }

    public function test_disabling_bandwidth_hides_pages_and_menu_and_enabling_restores_them(): void
    {
        $admin = $this->user(UserRole::Admin);
        $this->actingAs($admin)->get('/monitoring/bandwidth')->assertOk();
        $this->actingAs($admin)->get('/devices')->assertOk()->assertSee('Bandwidth');

        $this->actingAs($admin)->patch('/features/bandwidth', ['action' => 'disable'])->assertRedirect('/features');
        $this->assertFalse(app(FeatureFlags::class)->enabled(Feature::Bandwidth));

        $this->actingAs($admin)->get('/monitoring/bandwidth')->assertNotFound();
        $this->actingAs($admin)->getJson('/monitoring/bandwidth/data')->assertNotFound();
        $this->actingAs($admin)->get('/devices')->assertOk()->assertDontSee(route('monitor.bandwidth'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'feature.disabled']);

        $this->actingAs($admin)->patch('/features/bandwidth', ['action' => 'enable']);
        $this->actingAs($admin)->get('/monitoring/bandwidth')->assertOk();
    }

    public function test_reset_returns_to_env_default_and_unknown_feature_is_404(): void
    {
        $admin = $this->user(UserRole::Admin);
        $this->actingAs($admin)->patch('/features/network', ['action' => 'enable']);
        config(['netwatch.monitor.enabled' => false]);
        $this->assertTrue(app(FeatureFlags::class)->enabled(Feature::Network));

        $this->actingAs($admin)->patch('/features/network', ['action' => 'reset']);
        $this->assertFalse(app(FeatureFlags::class)->enabled(Feature::Network));

        $this->actingAs($admin)->patch('/features/tidak-ada', ['action' => 'enable'])->assertNotFound();
        $this->actingAs($admin)->patch('/features/network', ['action' => 'hapus'])->assertSessionHasErrors('action');
    }

    public function test_disabled_ticket_feature_blocks_public_form(): void
    {
        $this->get('/tickets/new')->assertOk();
        $this->actingAs($this->user(UserRole::Admin))->patch('/features/tickets', ['action' => 'disable']);
        $this->get('/tickets/new')->assertNotFound();
    }

    public function test_disabled_feature_makes_agent_api_discard_data_without_error(): void
    {
        config(['netwatch.browsing.enabled' => true]);
        $admin = $this->user(UserRole::Admin);
        $token = app(\App\Services\EnrollmentService::class)->createToken($admin, 'tes', null, 5, 24)['plain'];
        $enroll = $this->postJson('/api/agent/v1/enroll', [
            'enrollment_token' => $token, 'hostname' => 'PC-FF-01', 'os_family' => 'windows', 'os_version' => 'Windows 11', 'agent_version' => '1.3.0',
        ])->assertCreated();
        $agent = $enroll->json('api_token');
        $metrics = ['cpu_percent' => 5, 'memory_used_mb' => 1, 'memory_total_mb' => 8, 'disk_used_gb' => 1, 'disk_total_gb' => 2, 'uptime_seconds' => 1];

        $this->withToken($agent)->postJson('/api/agent/v1/heartbeat', ['metrics' => $metrics])->assertOk()->assertJson(['browsing_enabled' => true]);

        $this->actingAs($admin)->patch('/features/browsing', ['action' => 'disable']);
        $this->withToken($agent)->postJson('/api/agent/v1/heartbeat', ['metrics' => $metrics])->assertOk()->assertJson(['browsing_enabled' => false]);
        $this->withToken($agent)->postJson('/api/agent/v1/browsing', ['visits' => [
            ['kind' => 'connection', 'at' => now()->toIso8601String(), 'ip' => '93.184.216.34', 'port' => 443],
        ]])->assertSuccessful();
        $this->assertDatabaseCount('browsing_visits', 0);
    }

    private function user(UserRole $role): User
    {
        return User::query()->firstOrCreate(
            ['email' => "{$role->value}@example.test"],
            ['name' => $role->value, 'password' => 'Password-12345', 'role' => $role, 'is_active' => true],
        );
    }
}
