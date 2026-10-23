<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DashboardWidget;
use App\Enums\UserRole;
use App\Models\MonitorTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['netwatch.fleet_cache_seconds' => 0, 'netwatch.monitor.enabled' => true, 'netwatch.browsing.enabled' => true]);
    }

    public function test_default_dashboard_keeps_device_widgets_only(): void
    {
        $this->actingAs($this->user(UserRole::Admin))->get('/dashboard')->assertOk()
            ->assertSee('data-shown="devices_summary"', false)->assertSee('data-shown="devices_offline"', false)->assertSee('data-shown="devices_commands"', false)
            ->assertDontSee('data-shown="bandwidth_summary"', false)->assertDontSee('data-shown="browsing_summary"', false)->assertDontSee('data-shown="network_summary"', false);
    }

    public function test_user_can_pick_widgets_from_every_menu_and_choice_is_saved_per_user(): void
    {
        MonitorTarget::unguarded(fn () => MonitorTarget::query()->create(['name' => 'Switch Lt2', 'address' => '192.168.1.2', 'kind' => 'switch', 'method' => 'icmp', 'status' => 'offline', 'is_enabled' => true]));
        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)->post('/dashboard/widgets', ['action' => 'save', 'widgets' => ['bandwidth_summary', 'bandwidth_top', 'browsing_summary', 'browsing_top', 'network_summary', 'network_problems']])
            ->assertRedirect('/dashboard');

        $this->actingAs($admin)->get('/dashboard')->assertOk()
            ->assertSee('data-shown="bandwidth_summary"', false)->assertSee('data-shown="bandwidth_top"', false)->assertSee('data-shown="browsing_summary"', false)
            ->assertSee('data-shown="browsing_top"', false)->assertSee('data-shown="network_summary"', false)->assertSee('data-shown="network_problems"', false)->assertSee('Switch Lt2')
            ->assertDontSee('data-shown="devices_summary"', false)->assertDontSee('data-shown="devices_commands"', false);

        $other = $this->user(UserRole::Operator);
        $this->assertNull($other->fresh()->dashboard_widgets);
        $this->actingAs($other)->get('/dashboard')->assertOk()->assertSee('data-shown="devices_summary"', false);
    }

    public function test_reset_restores_default_and_empty_selection_is_allowed(): void
    {
        $admin = $this->user(UserRole::Admin);
        $this->actingAs($admin)->post('/dashboard/widgets', ['action' => 'save'])->assertRedirect('/dashboard');
        $this->assertSame([], $admin->fresh()->dashboard_widgets);
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSee('Belum ada widget yang dipilih')->assertDontSee('data-shown="devices_summary"', false);

        $this->actingAs($admin)->post('/dashboard/widgets', ['action' => 'reset']);
        $this->assertNull($admin->fresh()->dashboard_widgets);
        $this->actingAs($admin)->get('/dashboard')->assertSee('data-shown="devices_summary"', false);
    }

    public function test_non_admin_cannot_select_browsing_widgets(): void
    {
        $operator = $this->user(UserRole::Operator);
        $this->actingAs($operator)->post('/dashboard/widgets', ['action' => 'save', 'widgets' => ['browsing_summary', 'network_summary']])->assertRedirect('/dashboard');

        $this->assertSame(['network_summary'], $operator->fresh()->dashboard_widgets);
        $this->actingAs($operator)->get('/dashboard')->assertOk()->assertSee('data-shown="network_summary"', false)->assertDontSee('browsing_summary');
    }

    public function test_disabled_feature_hides_its_widgets_but_keeps_the_choice(): void
    {
        $admin = $this->user(UserRole::Admin);
        $this->actingAs($admin)->post('/dashboard/widgets', ['action' => 'save', 'widgets' => ['devices_summary', 'bandwidth_summary']]);

        $this->actingAs($admin)->patch('/features/bandwidth', ['action' => 'disable']);
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertDontSee('data-shown="bandwidth_summary"', false)->assertDontSee('bandwidth_summary');

        $this->actingAs($admin)->post('/dashboard/widgets', ['action' => 'save', 'widgets' => ['devices_summary']]);
        $this->assertContains('bandwidth_summary', $admin->fresh()->dashboard_widgets);   // pilihan lama tetap tersimpan

        $this->actingAs($admin)->patch('/features/bandwidth', ['action' => 'enable']);
        $this->actingAs($admin)->get('/dashboard')->assertSee('data-shown="bandwidth_summary"', false);
    }

    public function test_invalid_widget_key_is_rejected(): void
    {
        $admin = $this->user(UserRole::Admin);
        $this->actingAs($admin)->post('/dashboard/widgets', ['action' => 'save', 'widgets' => ['tidak_ada']])->assertSessionHasErrors('widgets.0');
        $this->actingAs($admin)->post('/dashboard/widgets', ['action' => 'hapus'])->assertSessionHasErrors('action');
        $this->assertNull($admin->fresh()->dashboard_widgets);
        $this->assertCount(9, DashboardWidget::cases());
    }

    private function user(UserRole $role): User
    {
        return User::query()->firstOrCreate(['email' => "{$role->value}@example.test"], ['name' => $role->value, 'password' => 'Password-12345', 'role' => $role, 'is_active' => true]);
    }
}
