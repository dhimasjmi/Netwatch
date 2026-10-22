<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\UserChangeGuard;
use Illuminate\Support\Facades\DB;

final class UserManager
{
    public function __construct(private readonly AuditLogger $audit, private readonly EventRecorder $events)
    {
    }

    /** @param  array{name: string, email: string, password: string, role: string} $data */
    public function create(User $actor, array $data): User
    {
        $user = User::query()->create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password' => $data['password'],
            'role' => UserRole::from($data['role']),
            'is_active' => true,
        ]);

        $this->audit->record('user.created', $actor, null, [
            'target_id' => $user->id, 'email' => $user->email, 'role' => $user->role->value,
        ]);
        $this->events->record('user.created', "Pengguna {$user->email} dibuat oleh {$actor->name}.", null, $actor, ['target_id' => $user->id]);

        return $user;
    }

    /**
     * @param  array{name: string, email: string, role: string}  $data
     *
     * @throws \App\Exceptions\UserManagementException
     */
    public function update(User $actor, User $target, array $data): User
    {
        $newRole = UserRole::from($data['role']);

        return DB::transaction(function () use ($actor, $target, $data, $newRole): User {
            $this->guard($actor, $target, $newRole, $target->is_active);

            $before = ['name' => $target->name, 'email' => $target->email, 'role' => $target->role->value];
            $target->forceFill([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'role' => $newRole,
            ])->save();

            $roleChanged = $before['role'] !== $newRole->value;
            $this->audit->record('user.updated', $actor, null, [
                'target_id' => $target->id, 'before' => $before, 'after' => $target->only(['name', 'email']) + ['role' => $newRole->value],
            ]);

            if ($roleChanged) {
                $this->revokeSessions($target); // Hak berubah → paksa login ulang agar berlaku seketika.
            }

            return $target;
        });
    }

    /** @throws \App\Exceptions\UserManagementException */
    public function setActive(User $actor, User $target, bool $active): void
    {
        DB::transaction(function () use ($actor, $target, $active): void {
            $this->guard($actor, $target, $target->role, $active);

            $target->forceFill(['is_active' => $active])->save();
            $this->audit->record($active ? 'user.activated' : 'user.deactivated', $actor, null, [
                'target_id' => $target->id, 'email' => $target->email,
            ]);

            if (! $active) {
                $this->revokeSessions($target);
                $this->events->record('user.deactivated', "Akun {$target->email} dinonaktifkan oleh {$actor->name}.", null, $actor, ['target_id' => $target->id]);
            }
        });
    }

    public function resetPassword(User $actor, User $target, string $newPassword): void
    {
        $target->forceFill(['password' => $newPassword, 'remember_token' => null])->save();
        $this->revokeSessions($target);

        $this->audit->record('user.password_reset', $actor, null, ['target_id' => $target->id, 'email' => $target->email]);
        $this->events->record('user.password_reset', "Kata sandi {$target->email} direset oleh {$actor->name}.", null, $actor, ['target_id' => $target->id]);
    }

    /** Menghapus sesi aktif di tabel sessions (driver database) sehingga pengguna langsung keluar. */
    private function revokeSessions(User $target): void
    {
        DB::table('sessions')->where('user_id', $target->id)->delete();
    }

    /** @throws \App\Exceptions\UserManagementException */
    private function guard(User $actor, User $target, UserRole $newRole, bool $newActive): void
    {
        // Kunci baris admin agar dua admin tidak saling menonaktifkan secara bersamaan.
        // PostgreSQL melarang FOR UPDATE bersama agregat, jadi ambil id lalu hitung di PHP.
        $otherAdmins = User::query()
            ->where('role', UserRole::Admin->value)
            ->where('is_active', true)
            ->where('id', '!=', $target->id)
            ->lockForUpdate()
            ->pluck('id')
            ->count();

        UserChangeGuard::assertAllowed(
            isSelf: $actor->is($target),
            currentRole: $target->role,
            currentActive: $target->is_active,
            newRole: $newRole,
            newActive: $newActive,
            otherActiveAdmins: $otherAdmins,
        );
    }
}
