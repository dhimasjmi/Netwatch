<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UserRole;
use App\Exceptions\UserManagementException;

/**
 * Aturan keselamatan perubahan akun, dibuat murni (tanpa database) agar mudah diuji.
 * Tujuan: admin tidak bisa mengunci dirinya sendiri atau menghilangkan admin terakhir.
 */
final class UserChangeGuard
{
    /**
     * @param  int  $otherActiveAdmins  jumlah admin aktif SELAIN akun yang diubah
     *
     * @throws UserManagementException
     */
    public static function assertAllowed(
        bool $isSelf,
        UserRole $currentRole,
        bool $currentActive,
        UserRole $newRole,
        bool $newActive,
        int $otherActiveAdmins,
    ): void {
        $wasActiveAdmin = $currentRole === UserRole::Admin && $currentActive;
        $staysActiveAdmin = $newRole === UserRole::Admin && $newActive;

        if (! $wasActiveAdmin || $staysActiveAdmin) {
            if ($isSelf && ! $newActive) {
                throw new UserManagementException('Anda tidak dapat menonaktifkan akun Anda sendiri.');
            }

            return;
        }

        if ($isSelf) {
            throw new UserManagementException(
                'Anda tidak dapat menurunkan peran atau menonaktifkan akun Anda sendiri. Minta admin lain melakukannya.'
            );
        }

        if ($otherActiveAdmins < 1) {
            throw new UserManagementException('Harus tersisa minimal satu administrator aktif.');
        }
    }
}
