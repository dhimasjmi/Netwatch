<?php

declare(strict_types=1);

namespace NetWatch\Agent\Browsing;

/**
 * Memilih koneksi keluar yang layak dilaporkan: hanya IP publik, bukan server NetWatch sendiri,
 * dan tidak mengulang pasangan (IP, proses) yang sudah dilaporkan dalam jendela waktu tertentu.
 */
final class ConnectionTracker
{
    /** @var array<string, int> */
    private array $seen = [];

    public function __construct(private readonly string $serverHost, private readonly int $windowSeconds = 1800)
    {
    }

    /**
     * @param  list<array{ip: string, port: int|null, process: string|null, host: string|null}>  $connections
     * @return list<array{ip: string, port: int|null, process: string|null, host: string|null}>
     */
    public function fresh(array $connections, int $now, int $limit): array
    {
        $result = [];
        $keys = [];

        foreach ($connections as $connection) {
            $ip = $connection['ip'];
            $key = $ip . '|' . strtolower((string) $connection['process']);

            if (!Sanitizer::isPublicIp($ip) || $ip === $this->serverHost || isset($keys[$key])) {
                continue;
            }

            if (isset($this->seen[$key]) && $now - $this->seen[$key] < $this->windowSeconds) {
                continue;
            }

            $keys[$key] = true;
            $result[] = $connection;

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /** Tandai sebagai terlaporkan, dipanggil hanya setelah server menerima kiriman. */
    public function markReported(array $connections, int $now): void
    {
        foreach ($connections as $connection) {
            $this->seen[$connection['ip'] . '|' . strtolower((string) $connection['process'])] = $now;
        }

        foreach ($this->seen as $key => $at) {
            if ($now - $at >= $this->windowSeconds) {
                unset($this->seen[$key]);
            }
        }
    }
}
