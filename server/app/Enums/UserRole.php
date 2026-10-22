<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Viewer = 'viewer';

    public function canRunCommands(): bool
    {
        return $this === self::Admin || $this === self::Operator;
    }

    /** Menangani tiket bantuan: administrator dan operator. */
    public function canHandleTickets(): bool
    {
        return $this === self::Admin || $this === self::Operator;
    }

    public function canManageFleet(): bool
    {
        return $this === self::Admin;
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Operator => 'Operator',
            self::Viewer => 'Viewer',
        };
    }
}
