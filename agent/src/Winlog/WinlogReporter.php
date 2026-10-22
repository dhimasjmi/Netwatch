<?php

declare(strict_types=1);

namespace NetWatch\Agent\Winlog;

use NetWatch\Agent\Http\ApiTransport;
use NetWatch\Agent\Platform\PlatformInterface;

/** Mengirim entri Windows Event Log yang baru ke server (event identik digabung dengan hitungan, bukan dikirim satu per satu). */
final class WinlogReporter
{
    private const MAX_BATCH = 200;
    private const INITIAL_LOOKBACK_SECONDS = 3600;
    private const LOGS = ['System', 'Application'];

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly ApiTransport $api,
        private readonly string $statePath,
    ) {
    }

    /**
     * @return int jumlah entri (setelah digabung) yang dikirim
     *
     * @throws \NetWatch\Agent\Platform\PlatformException
     */
    public function report(?int $now = null): int
    {
        $now ??= time();
        $state = WinlogState::load($this->statePath);

        foreach (self::LOGS as $log) {
            $state->cursors[$log] ??= ['time' => $now - self::INITIAL_LOOKBACK_SECONDS, 'record' => 0];
        }

        $after = min(array_map(static fn (array $c): int => $c['time'], $state->cursors));
        $fresh = self::newerThanCursors($this->platform->readEventLog($after), $state->cursors);

        if ($fresh === []) {
            $state->save($this->statePath);

            return 0;
        }

        $batch = array_slice(self::collapse($fresh), 0, self::MAX_BATCH);

        $this->api->post('winlog', ['events' => $batch]); // melempar bila gagal: kursor tidak maju, kiriman diulang

        foreach ($fresh as $event) {
            $cursor = $state->cursors[$event['log']] ?? ['time' => 0, 'record' => 0];

            if ($event['time'] > $cursor['time'] || ($event['time'] === $cursor['time'] && $event['record'] > $cursor['record'])) {
                $state->cursors[$event['log']] = ['time' => $event['time'], 'record' => $event['record']];
            }
        }
        $state->save($this->statePath);

        return count($batch);
    }

    /**
     * Hanya entri yang lebih baru dari kursor log-nya sendiri.
     *
     * @param  list<array{log: string, provider: string, id: int, level: int, time: int, record: int, message: string}>  $events
     * @param  array<string, array{time: int, record: int}>  $cursors
     * @return list<array{log: string, provider: string, id: int, level: int, time: int, record: int, message: string}>
     */
    public static function newerThanCursors(array $events, array $cursors): array
    {
        return array_values(array_filter($events, static function (array $e) use ($cursors): bool {
            $cursor = $cursors[$e['log']] ?? null;

            return $cursor !== null
                && in_array($e['log'], self::LOGS, true)
                && ($e['time'] > $cursor['time'] || ($e['time'] === $cursor['time'] && $e['record'] > $cursor['record']));
        }));
    }

    /**
     * Gabungkan entri dengan (log, provider, id, level) sama: dikirim sekali dengan jumlah, memakai entri terbaru.
     * Layanan yang crash berulang bisa menulis ratusan entri identik.
     *
     * @param  list<array{log: string, provider: string, id: int, level: int, time: int, record: int, message: string}>  $events
     * @return list<array<string, mixed>> urut waktu naik
     */
    public static function collapse(array $events): array
    {
        $groups = [];

        foreach ($events as $event) {
            $key = $event['log'] . '|' . $event['provider'] . '|' . $event['id'] . '|' . $event['level'];
            $count = ($groups[$key]['count'] ?? 0) + 1;

            if (!isset($groups[$key]) || $event['time'] >= $groups[$key]['time']) {
                $groups[$key] = $event;
            }

            $groups[$key]['count'] = $count;
        }

        $result = array_values($groups);
        usort($result, static fn (array $a, array $b): int => [$a['time'], $a['record']] <=> [$b['time'], $b['record']]);

        return array_map(static function (array $e): array {
            $e['message'] = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $e['message'])), 0, 300);

            return $e;
        }, $result);
    }
}
