<?php

declare(strict_types=1);

namespace App\Enums;

enum OsFamily: string
{
    case Windows = 'windows';
    case Linux = 'linux';

    public function label(): string
    {
        return match ($this) {
            self::Windows => 'Windows',
            self::Linux => 'Linux',
        };
    }
}
