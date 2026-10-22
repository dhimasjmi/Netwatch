<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Exceptions\UserManagementException;
use App\Support\UserChangeGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserChangeGuardTest extends TestCase
{
    /** @return array<string, array{bool, UserRole, bool, UserRole, bool, int, bool}> */
    public static function cases(): array
    {
        $A = UserRole::Admin;
        $O = UserRole::Operator;

        // isSelf, role sekarang, aktif sekarang, role baru, aktif baru, admin aktif lain, diizinkan?
        return [
            'admin lain menurunkan admin saat ada admin lain' => [false, $A, true, $O, true, 1, true],
            'menurunkan admin terakhir' => [false, $A, true, $O, true, 0, false],
            'menonaktifkan admin terakhir' => [false, $A, true, $A, false, 0, false],
            'menonaktifkan diri sendiri (admin)' => [true, $A, true, $A, false, 3, false],
            'menurunkan diri sendiri' => [true, $A, true, $O, true, 3, false],
            'diri sendiri tetap admin' => [true, $A, true, $A, true, 0, true],
            'menonaktifkan diri sendiri (operator)' => [true, $O, true, $O, false, 1, false],
            'mengaktifkan kembali admin nonaktif' => [false, $A, false, $A, true, 0, true],
            'ubah operator jadi viewer' => [false, $O, true, UserRole::Viewer, true, 0, true],
        ];
    }

    #[DataProvider('cases')]
    public function test_guard(bool $self, UserRole $role, bool $active, UserRole $newRole, bool $newActive, int $others, bool $allowed): void
    {
        if (! $allowed) {
            $this->expectException(UserManagementException::class);
        }

        UserChangeGuard::assertAllowed($self, $role, $active, $newRole, $newActive, $others);

        $this->addToAssertionCount(1);
    }
}
