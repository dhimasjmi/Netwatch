<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_open_user_pages(): void
    {
        foreach ([UserRole::Operator, UserRole::Viewer] as $role) {
            $this->actingAs($this->make($role, "{$role->value}@example.test"))->get('/users')->assertForbidden();
        }
    }

    public function test_admin_creates_user_and_action_is_audited(): void
    {
        $admin = $this->make(UserRole::Admin, 'admin@example.test');

        $this->actingAs($admin)->post('/users', [
            'name' => 'Budi', 'email' => 'Budi@Example.test', 'role' => 'operator',
            'password' => 'Sandi-Kuat-12345', 'password_confirmation' => 'Sandi-Kuat-12345',
        ])->assertRedirect('/users');

        $this->assertDatabaseHas('users', ['email' => 'budi@example.test', 'role' => 'operator', 'is_active' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'user_id' => $admin->id]);
    }

    public function test_weak_password_is_rejected(): void
    {
        $this->actingAs($this->make(UserRole::Admin, 'admin@example.test'))->post('/users', [
            'name' => 'Budi', 'email' => 'budi@example.test', 'role' => 'viewer',
            'password' => 'pendek', 'password_confirmation' => 'pendek',
        ])->assertSessionHasErrors('password');
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $admin = $this->make(UserRole::Admin, 'admin@example.test');

        $this->actingAs($admin)->post('/users', [
            'name' => 'X', 'email' => 'ADMIN@example.test', 'role' => 'viewer',
            'password' => 'Sandi-Kuat-12345', 'password_confirmation' => 'Sandi-Kuat-12345',
        ])->assertSessionHasErrors('email');
    }

    public function test_deactivating_user_revokes_sessions_and_blocks_login(): void
    {
        $admin = $this->make(UserRole::Admin, 'admin@example.test');
        $target = $this->make(UserRole::Operator, 'op@example.test');
        DB::table('sessions')->insert([
            'id' => 'sess-1', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time(),
        ]);

        $this->actingAs($admin)->patch("/users/{$target->id}/active")->assertRedirect();

        $this->assertFalse($target->fresh()->is_active);
        $this->assertDatabaseMissing('sessions', ['id' => 'sess-1']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.deactivated']);
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $admin = $this->make(UserRole::Admin, 'admin@example.test');

        $this->actingAs($admin)->patch("/users/{$admin->id}/active")->assertSessionHasErrors('user');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_last_admin_cannot_be_demoted_by_another_admin_if_it_leaves_none(): void
    {
        // Admin A diturunkan oleh admin B → A bukan admin terakhir (B ada), diizinkan.
        $a = $this->make(UserRole::Admin, 'a@example.test');
        $b = $this->make(UserRole::Admin, 'b@example.test');

        $this->actingAs($b)->patch("/users/{$a->id}", [
            'name' => 'A', 'email' => 'a@example.test', 'role' => 'viewer',
        ])->assertRedirect('/users');
        $this->assertSame(UserRole::Viewer, $a->fresh()->role);

        // Sekarang B satu-satunya admin dan mencoba menurunkan diri sendiri → ditolak.
        $this->actingAs($b)->patch("/users/{$b->id}", [
            'name' => 'B', 'email' => 'b@example.test', 'role' => 'operator',
        ])->assertSessionHasErrors('role');
        $this->assertSame(UserRole::Admin, $b->fresh()->role);
    }

    public function test_password_reset_changes_password_and_revokes_sessions(): void
    {
        $admin = $this->make(UserRole::Admin, 'admin@example.test');
        $target = $this->make(UserRole::Viewer, 'v@example.test');
        DB::table('sessions')->insert([
            'id' => 'sess-2', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time(),
        ]);

        $this->actingAs($admin)->put("/users/{$target->id}/password", [
            'password' => 'Sandi-Baru-98765', 'password_confirmation' => 'Sandi-Baru-98765',
        ])->assertRedirect('/users');

        $this->assertTrue(password_verify('Sandi-Baru-98765', $target->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'sess-2']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset']);
    }

    public function test_user_list_can_be_searched(): void
    {
        $admin = $this->make(UserRole::Admin, 'admin@example.test');
        $this->make(UserRole::Viewer, 'siti@example.test', 'Siti Aminah');

        $this->actingAs($admin)->get('/users?q=siti')->assertOk()->assertSee('Siti Aminah');
        $this->actingAs($admin)->get('/users?q=zzz-tidak-ada')->assertOk()->assertDontSee('Siti Aminah');
    }

    private function make(UserRole $role, string $email, string $name = 'Pengguna'): User
    {
        return User::query()->create([
            'name' => $name, 'email' => $email, 'password' => 'Password-12345', 'role' => $role, 'is_active' => true,
        ]);
    }
}
