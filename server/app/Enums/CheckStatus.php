<?php

declare(strict_types=1);

namespace App\Enums;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Fail = 'fail';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Aman',
            self::Warning => 'Perlu perhatian',
            self::Fail => 'Berisiko',
        };
    }
}
