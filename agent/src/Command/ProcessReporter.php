<?php

declare(strict_types=1);

namespace NetWatch\Agent\Command;

use NetWatch\Agent\Dto\ProcessInfo;
use NetWatch\Agent\Http\ApiClient;
use NetWatch\Agent\Platform\PlatformInterface;

/** Mengirim daftar proses (setara Task Manager) ke server. */
final class ProcessReporter
{
    private const MAX_PROCESSES = 500;

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly ApiClient $api,
    ) {
    }

    /** @return int jumlah proses yang dikirim */
    public function report(): int
    {
        $processes = $this->platform->listProcesses();

        // Urutkan menurut memori terbesar agar proses paling relevan tidak terpotong oleh batas jumlah.
        usort($processes, static fn (ProcessInfo $a, ProcessInfo $b): int => ($b->memoryMb ?? 0.0) <=> ($a->memoryMb ?? 0.0));
        $processes = array_slice($processes, 0, self::MAX_PROCESSES);

        $this->api->post('processes', [
            'processes' => array_map(static fn (ProcessInfo $p): array => $p->toArray(), $processes),
        ]);

        return count($processes);
    }
}
