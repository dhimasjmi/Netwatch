<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MonitoringTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_network_is_a_monitoring_tab_and_every_monitoring_page_has_fullscreen_toggle(): void
    {
        config(['netwatch.fleet_cache_seconds' => 0, 'netwatch.monitor.enabled' => true, 'netwatch.browsing.enabled' => true]);
        $admin = User::query()->create(['name' => 'A', 'email' => 'a@example.test', 'password' => 'Password-12345', 'role' => UserRole::Admin, 'is_active' => true]);

        foreach (['/devices', '/monitoring/bandwidth', '/monitoring/browsing', '/network'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()
                ->assertSee('mon-tabs', false)
                ->assertSee('data-fullscreen-toggle', false)
                ->assertSee(route('network.index'), false);
        }
    }

    public function test_disabled_network_removes_its_tab_and_menu_entry(): void
    {
        $admin = User::query()->create(['name' => 'A', 'email' => 'a@example.test', 'password' => 'Password-12345', 'role' => UserRole::Admin, 'is_active' => true]);
        $this->actingAs($admin)->patch('/features/network', ['action' => 'disable']);

        $this->actingAs($admin)->get('/devices')->assertOk()->assertDontSee(route('network.index'), false);
        $this->actingAs($admin)->get('/network')->assertNotFound();
    }

    public function test_viewer_does_not_get_browsing_tab(): void
    {
        $viewer = User::query()->create(['name' => 'V', 'email' => 'v@example.test', 'password' => 'Password-12345', 'role' => UserRole::Viewer, 'is_active' => true]);
        $this->actingAs($viewer)->get('/devices')->assertOk()->assertDontSee(route('monitor.browsing'), false)->assertSee(route('monitor.bandwidth'), false);
    }
}
