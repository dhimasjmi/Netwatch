<?php

declare(strict_types=1);

namespace NetWatch\Agent\Policy;

use InvalidArgumentException;

/**
 * Kebijakan blokir dari server. Agent memvalidasi ulang semuanya (tidak percaya begitu saja pada server)
 * karena nilainya berujung di file hosts, registry, dan aturan firewall milik SYSTEM.
 */
final class BlockPolicy
{
    public const MAX_ENTRIES = 1000;

    private const DOMAIN_PATTERN = '/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/';

    /**
     * @param  list<string>  $domains
     * @param  list<string>  $ips
     */
    private function __construct(
        public readonly string $hash,
        public readonly array $domains,
        public readonly array $ips,
    ) {
    }

    /** Kebijakan kosong, dipakai saat membersihkan semua blokir (mis. saat agent dihapus). */
    public static function none(): self
    {
        return new self(str_repeat('0', 64), [], []);
    }

    /**
     * Hash kanonik yang identik dengan perhitungan server (App\Support\DevicePolicy::hash): urut, unik, lalu SHA-256.
     * Agent melaporkan hash dari kondisi aturan yang benar-benar terpasang, bukan hash yang diterimanya dari server.
     *
     * @param  list<string>  $domains
     * @param  list<string>  $ips
     */
    public static function canonicalHash(array $domains, array $ips): string
    {
        $normalize = static function (array $values): array {
            $unique = array_values(array_unique($values));
            sort($unique, SORT_STRING);

            return $unique;
        };

        return hash('sha256', (string) json_encode(['d' => $normalize($domains), 'i' => $normalize($ips)], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException bila ada entri yang tidak valid; kebijakan ditolak seluruhnya, tidak diterapkan sebagian
     */
    public static function fromArray(array $data, string $serverHost): self
    {
        $hash = $data['hash'] ?? null;

        if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new InvalidArgumentException('Hash kebijakan tidak valid.');
        }

        $domains = self::stringList($data['domains'] ?? [], 'domains');
        $ips = self::stringList($data['ips'] ?? [], 'ips');
        $serverHost = strtolower($serverHost);

        foreach ($domains as $domain) {
            if (preg_match(self::DOMAIN_PATTERN, $domain) !== 1) {
                throw new InvalidArgumentException('Domain tidak valid dalam kebijakan.');
            }

            if ($serverHost === $domain || str_ends_with($serverHost, '.' . $domain)) {
                throw new InvalidArgumentException('Kebijakan memblokir alamat server NetWatch; ditolak agar agent tidak terputus.');
            }
        }

        foreach ($ips as $ip) {
            if (!self::isValidIpTarget($ip)) {
                throw new InvalidArgumentException('Alamat IP tidak valid dalam kebijakan.');
            }

            if ($ip === $serverHost) {
                throw new InvalidArgumentException('Kebijakan memblokir alamat server NetWatch; ditolak agar agent tidak terputus.');
            }
        }

        return new self($hash, $domains, $ips);
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $name): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_ENTRIES) {
            throw new InvalidArgumentException("Daftar {$name} tidak valid atau terlalu besar.");
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException("Daftar {$name} berisi nilai bukan teks.");
            }
        }

        return $value;
    }

    private static function isValidIpTarget(string $value): bool
    {
        $parts = explode('/', $value, 2);
        $address = $parts[0];

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (!isset($parts[1])) {
            return true;
        }

        $maxBits = str_contains($address, ':') ? 128 : 32;

        return preg_match('/^\d{1,3}$/', $parts[1]) === 1 && (int) $parts[1] <= $maxBits;
    }
}
