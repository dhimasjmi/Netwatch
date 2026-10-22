<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Normalisasi dan validasi target blokir (domain, IP, CIDR). Murni (tanpa database/jaringan) agar mudah diuji.
 * Semua nilai yang lolos di sini aman diteruskan ke agent: hanya karakter hostname / alamat IP yang valid.
 */
final class BlockTarget
{
    private const DOMAIN_PATTERN = '/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/';

    /** Awalan terkecil yang diizinkan, agar salah ketik tidak memblokir sebagian besar internet. */
    private const MIN_PREFIX_V4 = 8;
    private const MIN_PREFIX_V6 = 16;

    /** @throws InvalidArgumentException dengan pesan yang aman ditampilkan */
    public static function normalizeDomain(string $input): string
    {
        $value = strtolower(trim($input));
        $value = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value);   // buang skema
        $value = (string) preg_replace('#[/?\#].*$#', '', $value);               // buang path/query/fragmen
        $value = (string) preg_replace('#:\d+$#', '', $value);                    // buang port
        $value = (string) preg_replace('#^\*\.#', '', $value);                    // "*.situs.com" -> "situs.com"
        $value = rtrim($value, '.');

        if ($value !== '' && ! preg_match('/^[\x20-\x7e]+$/', $value) && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $value = $ascii === false ? $value : $ascii;
        }

        if (preg_match(self::DOMAIN_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('Domain tidak valid. Contoh yang benar: contoh.com atau *.contoh.com');
        }

        return $value;
    }

    /**
     * @return string IP atau CIDR dalam bentuk kanonik (bit host pada CIDR dikosongkan)
     *
     * @throws InvalidArgumentException
     */
    public static function normalizeIp(string $input): string
    {
        $parts = explode('/', trim($input), 2);
        $address = $parts[0];
        $packed = filter_var($address, FILTER_VALIDATE_IP) !== false ? inet_pton($address) : false;

        if ($packed === false) {
            throw new InvalidArgumentException('Alamat IP tidak valid. Contoh: 203.0.113.10 atau 203.0.113.0/24');
        }

        $maxBits = strlen($packed) * 8;
        $minPrefix = $maxBits === 32 ? self::MIN_PREFIX_V4 : self::MIN_PREFIX_V6;
        $prefix = $maxBits;

        if (isset($parts[1])) {
            if (preg_match('/^\d{1,3}$/', $parts[1]) !== 1 || (int) $parts[1] > $maxBits) {
                throw new InvalidArgumentException('Panjang awalan CIDR tidak valid.');
            }
            $prefix = (int) $parts[1];
        }

        if ($prefix < $minPrefix) {
            throw new InvalidArgumentException("Rentang terlalu luas (minimal /{$minPrefix}). Pastikan tidak salah ketik.");
        }

        if (self::isIpv4Mapped($packed)) {
            throw new InvalidArgumentException('Tulis alamat IPv4 biasa (mis. 203.0.113.10), bukan bentuk ::ffff:...');
        }

        $network = self::mask($packed, $prefix);

        if (self::isLoopbackOrUnspecified($network)) {
            throw new InvalidArgumentException('Alamat loopback/kosong tidak boleh diblokir.');
        }

        $text = (string) inet_ntop($network);

        return $prefix === $maxBits ? $text : "{$text}/{$prefix}";
    }

    /** Apakah $ip (alamat tunggal) berada di dalam $target (IP atau CIDR yang sudah dinormalisasi). */
    public static function covers(string $target, string $ip): bool
    {
        $parts = explode('/', $target, 2);
        $network = inet_pton($parts[0]);
        $address = inet_pton($ip);

        if ($network === false || $address === false || strlen($network) !== strlen($address)) {
            return false;
        }

        $prefix = isset($parts[1]) ? (int) $parts[1] : strlen($network) * 8;

        return self::mask($address, $prefix) === self::mask($network, $prefix);
    }

    /** Apakah $domain sama dengan atau induk dari $host (memblokir "contoh.com" juga memblokir "app.contoh.com"). */
    public static function domainCoversHost(string $domain, string $host): bool
    {
        $host = strtolower($host);

        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    private static function mask(string $packed, int $prefix): string
    {
        $bytes = array_values(unpack('C*', $packed) ?: []);

        foreach ($bytes as $index => $byte) {
            $bitsInByte = max(0, min(8, $prefix - $index * 8));
            $bytes[$index] = $bitsInByte === 8 ? $byte : ($byte & (0xFF << (8 - $bitsInByte)) & 0xFF);
        }

        return pack('C*', ...$bytes);
    }

    private static function isLoopbackOrUnspecified(string $network): bool
    {
        if (strlen($network) === 4) {
            return ord($network[0]) === 127 || $network === "\0\0\0\0";
        }

        return $network === str_repeat("\0", 16) || $network === str_repeat("\0", 15)."\1";
    }

    /** Bentuk IPv4-mapped (::ffff:a.b.c.d) tidak diterima: bisa dipakai menyelipkan IPv4 melewati pemeriksaan alamat server. */
    private static function isIpv4Mapped(string $packed): bool
    {
        return strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff");
    }
}
