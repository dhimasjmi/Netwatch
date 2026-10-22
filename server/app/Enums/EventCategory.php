<?php

declare(strict_types=1);

namespace App\Enums;

enum EventCategory: string
{
    case Availability = 'availability';
    case Connectivity = 'connectivity';
    case Addressing = 'addressing';
    case Dns = 'dns';
    case Performance = 'performance';
    case Command = 'command';
    case Enrollment = 'enrollment';
    case Security = 'security';
    case Policy = 'policy';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Availability => 'Ketersediaan',
            self::Connectivity => 'Konektivitas',
            self::Addressing => 'Alamat IP',
            self::Dns => 'DNS',
            self::Performance => 'Kinerja',
            self::Command => 'Perintah',
            self::Enrollment => 'Pendaftaran perangkat',
            self::Security => 'Keamanan',
            self::Policy => 'Policies',
            self::System => 'Sistem Windows',
        };
    }
}
