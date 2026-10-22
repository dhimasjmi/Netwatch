<?php

declare(strict_types=1);

namespace App\Enums;

enum TargetStatus: string
{
    case Unknown = 'unknown';
    case Online = 'online';
    case Warning = 'warning';
    case Offline = 'offline';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Belum dicek',
            self::Online => 'Online',
            self::Warning => 'Lambat',
            self::Offline => 'Offline',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Online => 'badge-ok',
            self::Warning => 'badge-pending',
            self::Offline => 'badge-off',
            self::Unknown => '',
        };
    }

    public function isUp(): bool
    {
        return $this === self::Online || $this === self::Warning;
    }
}
