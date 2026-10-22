<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = $this->user(UserRole::Admin, active: false);

        $this->post('/login', ['email' => $user->email, 'password' => 'Password-12345'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_active_user_can_log_in(): void
    {
        $user = $this->user(UserRole::Operator);

        $this->post('/login', ['email' => $user->email, 'password' => 'Password-12345'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_viewer_cannot_issue_commands(): void
    {
        $this->actingAs($this->user(UserRole::Viewer))
            ->postJson(route('devices.commands.store', $this->device()), ['type' => 'refresh_processes'])
            ->assertForbidden();
    }

    public function test_operator_can_issue_commands_but_not_power_control(): void
    {
        $operator = $this->user(UserRole::Operator);
        $device = $this->device();

        $this->actingAs($operator)
            ->postJson(route('devices.commands.store', $device), ['type' => 'refresh_processes'])
            ->assertCreated();

        $this->actingAs($operator)
            ->postJson(route('devices.commands.store', $device), ['type' => 'restart_computer'])
            ->assertForbidden();
    }

    public function test_admin_can_restart_device(): void
    {
        $this->actingAs($this->user(UserRole::Admin))
            ->postJson(route('devices.commands.store', $this->device()), ['type' => 'restart_computer'])
            ->assertCreated();

        $this->assertDatabaseHas('device_commands', ['type' => 'restart_computer', 'status' => 'pending']);
    }

    public function test_protected_process_cannot_be_terminated(): void
    {
        $this->actingAs($this->user(UserRole::Admin))
            ->postJson(route('devices.commands.store', $this->device()), [
                'type' => 'terminate_process', 'pid' => 4, 'process_name' => 'lsass.exe',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_terminate_requires_pid_and_name(): void
    {
        $this->actingAs($this->user(UserRole::Admin))
            ->postJson(route('devices.commands.store', $this->device()), ['type' => 'terminate_process'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pid', 'process_name']);
    }

    public function test_only_admin_sees_audit_log(): void
    {
        $this->actingAs($this->user(UserRole::Operator))->get('/audit-logs')->assertForbidden();
        $this->actingAs($this->user(UserRole::Admin))->get('/audit-logs')->assertOk();
    }

    // ---------- helper ----------

    private function user(UserRole $role, bool $active = true): User
    {
        return User::query()->create([
            'name' => $role->label(),
            'email' => "{$role->value}".($active ? '' : '-off')."@example.test",
            'password' => 'Password-12345',
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    private function device(): Device
    {
        return Device::query()->create([
            'hostname' => 'PC-TEST',
            'os_family' => 'linux',
            'agent_version' => '1.0.0',
            'token_hash' => hash('sha256', 'token-'.uniqid()),
            'enrolled_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
