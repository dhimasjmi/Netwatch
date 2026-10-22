<?php

declare(strict_types=1);

namespace NetWatch\Agent\Browsing;

/** Fungsi murni untuk membersihkan data browsing sebelum dikirim ke server. */
final class Sanitizer
{
    /** Selisih detik antara epoch Windows/Chrome (1601) dan Unix (1970). */
    private const CHROME_EPOCH_OFFSET = 11_644_473_600;

    /** Waktu Chrome/Edge (mikrodetik sejak 1601) menjadi detik Unix. */
    public static function chromeToUnix(int $chromeTime): int
    {
        return intdiv($chromeTime, 1_000_000) - self::CHROME_EPOCH_OFFSET;
    }

    public static function unixToChrome(int $unix): int
    {
        return ($unix + self::CHROME_EPOCH_OFFSET) * 1_000_000;
    }

    /**
     * Hanya http(s) tanpa kredensial, query, dan fragmen (kata pencarian dan token tidak boleh meninggalkan komputer).
     * Alamat internal browser (chrome://, edge://, file://) dan localhost dibuang.
     */
    public static function url(string $url): ?string
    {
        if (strlen($url) > 4096) {
            return null;
        }

        $parts = parse_url(trim($url));

        if ($parts === false || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');

        return strtolower((string) $parts['scheme']) . '://' . $host . mb_substr($path, 0, 500);
    }

    /** IP tujuan yang layak dicatat: publik saja (alamat LAN, loopback, link-local, dan multicast tidak berguna dan berisik). */
    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $lower = strtolower($ip);

        if (str_contains($lower, ':')) {
            return !(str_starts_with($lower, 'fe8') || str_starts_with($lower, 'fe9') || str_starts_with($lower, 'fea')
                || str_starts_with($lower, 'feb') || str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd')
                || str_starts_with($lower, 'ff') || str_starts_with($lower, '::ffff:'));
        }

        return !str_starts_with($ip, '100.64.') && (int) explode('.', $ip)[0] < 224; // CGNAT dan multicast
    }
}
