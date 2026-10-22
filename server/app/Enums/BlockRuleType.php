<?php

declare(strict_types=1);

namespace App\Enums;

enum BlockRuleType: string
{
    case Domain = 'domain';
    case Ip = 'ip';

    public function label(): string
    {
        return match ($this) {
            self::Domain => 'Situs (domain)',
            self::Ip => 'Alamat IP / rentang',
        };
    }
}
