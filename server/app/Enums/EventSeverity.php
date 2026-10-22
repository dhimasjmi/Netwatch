<?php

declare(strict_types=1);

namespace App\Enums;

enum EventSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Warning => 'Peringatan',
            self::Critical => 'Kritis',
        };
    }
}
