<?php

declare(strict_types=1);

namespace App\Enums;

enum TargetKind: string
{
    case Switch = 'switch';
    case AccessPoint = 'access_point';
    case Router = 'router';
    case Printer = 'printer';
    case Server = 'server';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Switch => 'Switch',
            self::AccessPoint => 'Access Point',
            self::Router => 'Router / Firewall',
            self::Printer => 'Printer',
            self::Server => 'Server',
            self::Other => 'Lainnya',
        };
    }
}
