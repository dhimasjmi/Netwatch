<?php

declare(strict_types=1);

namespace App\Support;

/** Memvalidasi baris inventaris dari agent (tidak dipercaya). Murni agar mudah diuji. */
final class InventoryEntry
{
    /** Port UDP/dinamis di atas ini adalah port sementara aplikasi, bukan layanan yang layak dipantau. */
    public const EPHEMERAL_START = 49152;

    /**
     * @param  array<string, mixed>  $raw
     * @return array{protocol: string, address: string, port: int, process: string|null, exposed: bool}|null
     */
    public static function port(array $raw): ?array
    {
        $protocol = is_string($raw['protocol'] ?? null) ? strtolower($raw['protocol']) : '';
        $address = is_string($raw['address'] ?? null) ? strtolower(trim($raw['address'], '[] ')) : '';
        $port = isset($raw['port']) && is_numeric($raw['port']) ? (int) $raw['port'] : 0;

        if (!in_array($protocol, ['tcp', 'udp'], true) || filter_var($address, FILTER_VALIDATE_IP) === false || $port < 1 || $port > 65535) {
            return null;
        }

        if ($protocol === 'udp' && $port >= self::EPHEMERAL_START) {
            return null;
        }

        return [
            'protocol' => $protocol,
            'address' => $address,
            'port' => $port,
            'process' => self::label(is_string($raw['process'] ?? null) ? basename(str_replace('\\', '/', $raw['process'])) : null, 100),
            'exposed' => !self::isLoopback($address),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{name: string, display_name: string|null, state: string, start_mode: string|null}|null
     */
    public static function service(array $raw): ?array
    {
        $name = self::label($raw['name'] ?? null, 256);
        $state = self::label($raw['state'] ?? null, 20);

        if ($name === null || $state === null) {
            return null;
        }

        return [
            'name' => $name,
            'display_name' => self::label($raw['display'] ?? null, 256),
            'state' => $state,
            'start_mode' => self::label($raw['start'] ?? null, 20),
        ];
    }

    public static function isLoopback(string $address): bool
    {
        return $address === '::1' || str_starts_with($address, '127.');
    }

    private static function label(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value));

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }
}
