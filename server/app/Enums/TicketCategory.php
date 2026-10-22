<?php

declare(strict_types=1);

namespace App\Enums;

enum TicketCategory: string
{
    case Network = 'network';
    case Hardware = 'hardware';
    case Software = 'software';
    case Account = 'account';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Network => 'Jaringan / internet',
            self::Hardware => 'Perangkat keras',
            self::Software => 'Aplikasi / perangkat lunak',
            self::Account => 'Akun / kata sandi',
            self::Other => 'Lainnya',
        };
    }
}
