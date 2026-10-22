<?php

declare(strict_types=1);

namespace App\Support;

final class AgentVersion
{
    /** Versi kosong/tidak valid dianggap usang, sebab agent yang sehat selalu melaporkan versinya. */
    public static function isOutdated(?string $version, string $latest): bool
    {
        if ($version === null || preg_match('/^\d+(\.\d+){1,3}$/', $version) !== 1) {
            return true;
        }

        return version_compare($version, $latest, '<');
    }
}
