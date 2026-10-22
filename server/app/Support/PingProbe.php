<?php

declare(strict_types=1);

namespace App\Support;

/** Menyusun perintah ping yang aman (tanpa shell) dan membaca hasilnya. Murni agar mudah diuji. */
final class PingProbe
{
    /**
     * @return list<string>|null null bila alamat bukan IP valid atau tidak layak di-ping (loopback, 0.0.0.0, multicast)
     */
    public static function command(?string $ip, bool $windows): ?array
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false || self::isUnusable($ip)) {
            return null;
        }

        return $windows
            ? ['ping', '-n', '1', '-w', '1000', $ip]
            : ['ping', '-c', '1', '-W', '1', $ip];
    }

    /** Berhasil bila proses selesai normal dan ada balasan (TTL), karena Windows bisa berkode 0 untuk "host unreachable". */
    public static function succeeded(int $exitCode, string $output): bool
    {
        return $exitCode === 0 && preg_match('/\bttl\s*=/i', $output) === 1;
    }

    public static function milliseconds(string $output): ?float
    {
        if (preg_match('/(?:time|waktu)\s*([=<])\s*([\d]+(?:[.,]\d+)?)\s*ms/i', $output, $m) !== 1) {
            return null;
        }

        return $m[1] === '<' ? 0.5 : (float) str_replace(',', '.', $m[2]);
    }

    private static function isUnusable(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $lower = strtolower($ip);

            return $lower === '::1' || $lower === '::' || str_starts_with($lower, 'ff');
        }

        $first = (int) explode('.', $ip)[0];

        return $first === 0 || $first === 127 || $first >= 224; // 0.x, loopback, multicast, 255.255.255.255
    }
}
