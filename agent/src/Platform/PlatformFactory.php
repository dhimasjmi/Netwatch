<?php

declare(strict_types=1);

namespace NetWatch\Agent\Platform;

final class PlatformFactory
{
    /** @throws PlatformException untuk OS yang belum didukung */
    public static function create(): PlatformInterface
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => new WindowsPlatform(),
            'Linux' => new LinuxPlatform(),
            default => throw new PlatformException('Sistem operasi belum didukung: ' . PHP_OS_FAMILY),
        };
    }
}
