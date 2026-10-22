<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Memvalidasi dan membersihkan satu catatan browsing dari agent. Murni (tanpa database) agar mudah diuji.
 * Agent tidak dipercaya: semua nilai diperiksa ulang, dan query/fragmen/kredensial dalam URL selalu dibuang.
 */
final class BrowsingEntry
{
    public const MAX_AGE_DAYS = 7;
    private const HOST_PATTERN = '/^(?=.{1,253}$)[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?(\.[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?)*$/';

    /**
     * @param  array<string, mixed>  $raw
     * @param  'domain'|'path'  $detail domain = hanya nama situs; path = simpan juga alamat halaman (tanpa query)
     * @return array<string, mixed>|null baris siap simpan (tanpa device_id), null bila ditolak
     */
    public static function normalize(array $raw, string $detail, CarbonImmutable $now): ?array
    {
        $kind = $raw['kind'] ?? null;
        if ($kind !== 'url' && $kind !== 'connection') {
            return null;
        }

        $at = self::time($raw['at'] ?? null, $now);
        if ($at === null) {
            return null;
        }

        $ip = self::ip($raw['ip'] ?? null);
        $host = null;
        $url = null;

        if ($kind === 'url') {
            $parsed = self::parseUrl($raw['url'] ?? null);
            if ($parsed === null) {
                return null;
            }
            [$host, $path] = $parsed;
            $url = $detail === 'path' ? mb_substr($host.$path, 0, 1000) : null;
            $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : $ip;
        } else {
            if ($ip === null) {
                return null;
            }
            $host = self::host($raw['host'] ?? null);
        }

        $port = isset($raw['port']) && is_numeric($raw['port']) && (int) $raw['port'] >= 1 && (int) $raw['port'] <= 65535 ? (int) $raw['port'] : null;
        $process = self::label($raw['process'] ?? null, 100);
        $browser = in_array($raw['browser'] ?? null, ['chrome', 'edge'], true) ? $raw['browser'] : null;

        // Waktu dibulatkan ke menit pada kunci dedup agar koneksi yang sama dalam menit yang sama tidak berulang.
        $hash = sha1(implode('|', [$kind, $at->format('Y-m-d H:i'), $host, $ip, $port, $process, $url]));

        return [
            'visited_at' => $at->utc()->format('Y-m-d H:i:sP'),
            'kind' => $kind,
            'host' => $host,
            'ip' => $ip,
            'port' => $port,
            'url' => $url,
            'process' => $process,
            'browser' => $browser,
            'dedupe_hash' => $hash,
        ];
    }

    /** @return array{0: string, 1: string}|null [host, path] atau null bila bukan http(s) yang valid */
    private static function parseUrl(mixed $value): ?array
    {
        if (!is_string($value) || strlen($value) > 2048) {
            return null;
        }

        $parts = parse_url(trim($value));
        if ($parts === false || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }

        $host = self::host($parts['host'] ?? null);
        if ($host === null) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');

        return [$host, preg_replace('/[\x00-\x1F\x7F\s]/u', '', $path) ?? ''];
    }

    private static function host(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $host = strtolower(rtrim(trim($value, " \t[]"), '.'));
        if ($host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        return preg_match(self::HOST_PATTERN, $host) === 1 ? $host : null;
    }

    private static function ip(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false ? strtolower($value) : null;
    }

    private static function time(mixed $value, CarbonImmutable $now): ?CarbonImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        try {
            $at = CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }

        if ($at->gt($now->addMinutes(5)) || $at->lt($now->subDays(self::MAX_AGE_DAYS))) {
            return null;
        }

        return $at;
    }

    private static function label(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $value))));

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }
}
