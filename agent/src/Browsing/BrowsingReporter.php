<?php

declare(strict_types=1);

namespace NetWatch\Agent\Browsing;

use NetWatch\Agent\Http\ApiTransport;
use NetWatch\Agent\Platform\PlatformException;
use NetWatch\Agent\Platform\PlatformInterface;
use NetWatch\Agent\Support\Logger;

/** Mengumpulkan riwayat browser dan koneksi keluar, lalu mengirimnya ke server dalam satu batch. */
final class BrowsingReporter
{
    private const MAX_BATCH = 500;
    private const MAX_CONNECTIONS = 150;
    private const MAX_PER_FILE = 200;
    private const INITIAL_LOOKBACK_SECONDS = 600; // berkas baru: hanya kunjungan 10 menit terakhir, bukan seluruh riwayat lama

    private ?bool $historyAvailable = null;
    /** @var array<string, int> */
    private array $warnedAt = [];

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly ApiTransport $api,
        private readonly Logger $logger,
        private readonly string $statePath,
        private readonly ConnectionTracker $tracker,
        private readonly HistoryReader $reader = new HistoryReader(),
    ) {
    }

    /** @return int jumlah catatan yang dikirim */
    public function report(?int $now = null): int
    {
        $now ??= time();
        $state = BrowsingState::load($this->statePath);
        $visits = [];
        $newCursors = [];

        foreach ($this->historyFiles() as $file) {
            $budget = self::MAX_BATCH - self::MAX_CONNECTIONS - count($visits);

            if ($budget <= 0) {
                break;
            }

            $key = $file['browser'] . '|' . $file['path'];

            if (!isset($state->cursors[$key])) {
                $state->cursors[$key] = Sanitizer::unixToChrome($now - self::INITIAL_LOOKBACK_SECONDS);
                $state->save($this->statePath);
            }

            try {
                $rows = $this->reader->read($file['path'], $state->cursors[$key], min(self::MAX_PER_FILE, $budget));
            } catch (\RuntimeException $e) {
                $this->warnOnce($key, $e->getMessage(), $now);

                continue;
            }

            foreach ($rows as $row) {
                $url = Sanitizer::url($row['url']);
                $newCursors[$key] = $row['chrome_time'];

                if ($url !== null) {
                    $visits[] = [
                        'kind' => 'url',
                        'at' => gmdate('c', Sanitizer::chromeToUnix($row['chrome_time'])),
                        'url' => $url,
                        'browser' => $file['browser'],
                    ];
                }
            }
        }

        $connections = $this->tracker->fresh($this->connections($now), $now, self::MAX_CONNECTIONS);

        foreach ($connections as $connection) {
            $visits[] = array_filter([
                'kind' => 'connection',
                'at' => gmdate('c', $now),
                'ip' => $connection['ip'],
                'port' => $connection['port'],
                'host' => $connection['host'],
                'process' => $connection['process'],
            ], static fn ($v): bool => $v !== null);
        }

        if ($newCursors !== []) {
            // Kursor maju meski semua URL-nya dibuang (mis. chrome://), supaya tidak dibaca ulang terus.
            foreach ($newCursors as $key => $cursor) {
                $state->cursors[$key] = $cursor;
            }
        }

        if ($visits === []) {
            if ($newCursors !== []) {
                $state->save($this->statePath);
            }

            return 0;
        }

        $this->api->post('browsing', ['visits' => $visits]); // melempar bila gagal: kursor tidak maju, kiriman diulang

        $state->save($this->statePath);
        $this->tracker->markReported($connections, $now);

        return count($visits);
    }

    /** @return list<array{browser: string, path: string}> */
    private function historyFiles(): array
    {
        if ($this->historyAvailable === null) {
            $this->historyAvailable = HistoryReader::isAvailable();

            if (!$this->historyAvailable) {
                $this->logger->warning('Ekstensi pdo_sqlite tidak ada: riwayat browser tidak dibaca (hanya koneksi IP). Pasang ulang agent 1.3.0 dari installer terbaru.');
            }
        }

        return $this->historyAvailable ? $this->platform->browserHistoryFiles() : [];
    }

    /** @return list<array{ip: string, port: int|null, process: string|null, host: string|null}> */
    private function connections(int $now): array
    {
        try {
            return $this->platform->listConnections();
        } catch (PlatformException $e) {
            $this->warnOnce('connections', 'Gagal membaca koneksi: ' . $e->getMessage(), $now);

            return [];
        }
    }

    /** Peringatan yang sama dicatat paling sering sekali per jam agar log tidak penuh. */
    private function warnOnce(string $key, string $message, int $now): void
    {
        if (($this->warnedAt[$key] ?? 0) + 3600 <= $now) {
            $this->warnedAt[$key] = $now;
            $this->logger->warning($message);
        }
    }
}
