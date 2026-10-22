<?php

declare(strict_types=1);

namespace NetWatch\Agent;

use NetWatch\Agent\Browsing\BrowsingReporter;
use NetWatch\Agent\Command\CommandExecutor;
use NetWatch\Agent\Command\ProcessReporter;
use NetWatch\Agent\Http\ApiClient;
use NetWatch\Agent\Http\ApiException;
use NetWatch\Agent\Inventory\AssetReporter;
use NetWatch\Agent\Inventory\InventoryReporter;
use NetWatch\Agent\Platform\PlatformInterface;
use NetWatch\Agent\Policy\PolicyApplier;
use NetWatch\Agent\Support\Logger;
use NetWatch\Agent\Winlog\WinlogReporter;

/** Loop utama: heartbeat berkala, menjalankan perintah, dan mengirim snapshot proses. */
final class Agent
{
    private const MIN_INTERVAL_SECONDS = 5;
    private const MAX_BACKOFF_SECONDS = 60;

    private bool $running = true;
    private int $heartbeatInterval = 30;
    private int $snapshotInterval = 300;
    private const BROWSING_INTERVAL_SECONDS = 120;
    private const INVENTORY_INTERVAL_SECONDS = 600;
    private const ASSETS_INTERVAL_SECONDS = 300;
    private const WINLOG_INTERVAL_SECONDS = 300;
    private bool $browsingEnabled = false;
    private bool $winlogEnabled = false;
    private ?float $offlineSince = null;

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly ApiClient $api,
        private readonly CommandExecutor $executor,
        private readonly ProcessReporter $reporter,
        private readonly Logger $logger,
        private readonly ?PolicyApplier $policy = null,
        private readonly ?BrowsingReporter $browsing = null,
        private readonly ?InventoryReporter $inventory = null,
        private readonly ?WinlogReporter $winlog = null,
        private readonly ?AssetReporter $assets = null,
    ) {
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /** @param  int|null  $maxCycles hanya untuk pengujian; null berarti berjalan terus */
    public function run(?int $maxCycles = null): void
    {
        $this->logger->info('Agent dimulai (v' . Version::CURRENT . ').');

        $failures = 0;
        $nextSnapshotAt = 0.0;
        $nextBrowsingAt = 0.0;
        $nextInventoryAt = microtime(true) + 15; // beri waktu heartbeat pertama selesai dulu
        $nextWinlogAt = microtime(true) + 30;
        $nextAssetsAt = microtime(true) + 20;
        $cycles = 0;

        while ($this->running && ($maxCycles === null || $cycles < $maxCycles)) {
            $cycles++;

            try {
                $this->heartbeatAndHandleCommands();

                if (microtime(true) >= $nextSnapshotAt) {
                    $this->reporter->report();
                    $nextSnapshotAt = microtime(true) + $this->snapshotInterval;
                }

                if ($this->browsingEnabled && $this->browsing !== null && microtime(true) >= $nextBrowsingAt) {
                    $nextBrowsingAt = microtime(true) + self::BROWSING_INTERVAL_SECONDS;
                    $this->guarded('browsing', fn () => $this->browsing?->report());
                }

                if ($this->inventory !== null && microtime(true) >= $nextInventoryAt) {
                    $nextInventoryAt = microtime(true) + self::INVENTORY_INTERVAL_SECONDS;
                    $this->guarded('inventaris port/layanan', fn () => $this->inventory?->report());
                }

                if ($this->assets !== null && microtime(true) >= $nextAssetsAt) {
                    $nextAssetsAt = microtime(true) + self::ASSETS_INTERVAL_SECONDS;
                    $this->guarded('inventaris perangkat keras/lunak', fn () => $this->assets?->report());
                }

                if ($this->winlogEnabled && $this->winlog !== null && microtime(true) >= $nextWinlogAt) {
                    $nextWinlogAt = microtime(true) + self::WINLOG_INTERVAL_SECONDS;
                    $this->guarded('Windows Event Log', fn () => $this->winlog?->report());
                }

                $failures = 0;
                $this->offlineSince = null;

                if ($maxCycles !== null && $cycles >= $maxCycles) {
                    break;
                }

                $this->sleep($this->heartbeatInterval);
            } catch (ApiException $e) {
                if ($e->isAuthFailure()) {
                    $this->logger->error('Token ditolak server (perangkat dicabut?). Agent berhenti.');
                    $this->running = false;

                    return;
                }

                $failures++;
                $this->logger->warning('Gagal berkomunikasi dengan server: ' . $e->getMessage());
                $this->handleServerUnreachable($e);
                $this->sleep($this->backoff($failures));
            } catch (\Throwable $e) {
                // Kesalahan tak terduga tidak boleh mematikan agent; catat lalu coba lagi.
                $failures++;
                $this->logger->error('Kesalahan tak terduga: ' . $e::class . ': ' . $e->getMessage());
                $this->sleep($this->backoff($failures));
            }
        }

        $this->logger->info('Agent berhenti.');
    }

    /**
     * Hanya kegagalan koneksi (bukan balasan HTTP error) yang dihitung "di luar jaringan": server yang menjawab 5xx
     * berarti PC masih di jaringan yang sama dan blokir tetap berlaku.
     */
    private function handleServerUnreachable(ApiException $e): void
    {
        if ($e->statusCode !== 0 || $this->policy === null) {
            $this->offlineSince = null;

            return;
        }

        $this->offlineSince ??= microtime(true);
        $this->policy->releaseWhenOffline((int) (microtime(true) - $this->offlineSince));
    }

    private function heartbeatAndHandleCommands(): void
    {
        $metrics = $this->platform->collectMetrics()->toArray();
        $interfaces = array_map(
            static fn ($stat): array => $stat->toArray(),
            array_slice($this->platform->collectNetworkInterfaces(), 0, 32),
        );

        $response = $this->api->post('heartbeat', [
            'agent_version' => Version::CURRENT,
            'metrics' => $metrics,
            'interfaces' => $interfaces,
            'policy' => $this->policy?->report(),
        ]);

        $this->heartbeatInterval = $this->boundedInterval($response['heartbeat_interval'] ?? null, $this->heartbeatInterval);
        $this->snapshotInterval = $this->boundedInterval($response['process_snapshot_interval'] ?? null, $this->snapshotInterval);

        $this->browsingEnabled = ($response['browsing_enabled'] ?? false) === true;
        $this->winlogEnabled = ($response['winlog_enabled'] ?? false) === true;

        if ($this->policy !== null) {
            is_array($response['policy'] ?? null) ? $this->policy->apply($response['policy']) : $this->policy->nothingToApply();
        }

        $commands = $response['commands'] ?? [];

        foreach (is_array($commands) ? $commands : [] as $command) {
            if (is_array($command) && isset($command['id'], $command['type'])) {
                $this->handleCommand($command);
            }
        }
    }

    /** Kegagalan pengumpulan data tambahan tidak boleh mengganggu heartbeat; hanya token ditolak yang diteruskan. */
    private function guarded(string $what, callable $task): void
    {
        try {
            $task();
        } catch (ApiException $e) {
            if ($e->isAuthFailure()) {
                throw $e;
            }
            $this->logger->warning("Gagal mengirim data {$what} (dicoba lagi): " . $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->warning("Gagal mengumpulkan data {$what}: " . $e->getMessage());
        }
    }

    /** @param  array<string, mixed>  $command */
    private function handleCommand(array $command): void
    {
        $id = (string) $command['id'];
        $type = (string) $command['type'];
        $payload = is_array($command['payload'] ?? null) ? $command['payload'] : [];

        $this->logger->info("Menjalankan perintah {$type} ({$id}).");
        $outcome = $this->executor->execute($type, $payload);

        if (!$outcome->succeeded) {
            $this->logger->warning("Perintah {$id} gagal: {$outcome->error}");
        }

        $this->api->post("commands/{$id}/result", $outcome->toPayload());
    }

    private function boundedInterval(mixed $requested, int $current): int
    {
        return is_numeric($requested) ? max(self::MIN_INTERVAL_SECONDS, min(3600, (int) $requested)) : $current;
    }

    /** Backoff eksponensial dengan jitter agar ratusan agent tidak menyerang server bersamaan. */
    private function backoff(int $failures): int
    {
        $base = min(self::MAX_BACKOFF_SECONDS, $this->heartbeatInterval * (2 ** min($failures, 6)));

        return $base + random_int(0, max(1, intdiv($base, 5)));
    }

    private function sleep(int $seconds): void
    {
        $deadline = microtime(true) + $seconds;

        while ($this->running && microtime(true) < $deadline) {
            usleep(250_000);
        }
    }
}
