<?php

declare(strict_types=1);

namespace App\Enums;

enum ConnectionStatus: string
{
    case Online = 'online';
    case Offline = 'offline';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Offline => 'Offline',
        };
    }
}
