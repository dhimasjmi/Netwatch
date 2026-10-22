<?php

declare(strict_types=1);

namespace NetWatch\Agent\Support;

/** Proses sistem yang tidak boleh diakhiri, apa pun perintah dari server (pertahanan berlapis). */
final class ProtectedProcesses
{
    private const NAMES = [
        'system', 'system idle process', 'registry', 'smss', 'csrss', 'wininit', 'winlogon',
        'services', 'lsass', 'fontdrvhost', 'dwm', 'init', 'systemd', 'kthreadd', 'sshd',
        'php', 'php-cgi', 'netwatch-agent',
    ];

    public static function normalize(string $name): string
    {
        return strtolower((string) preg_replace('/\.exe$/i', '', trim($name)));
    }

    public static function isProtected(string $name): bool
    {
        return in_array(self::normalize($name), self::NAMES, true);
    }
}
