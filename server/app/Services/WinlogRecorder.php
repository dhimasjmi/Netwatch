<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\Event;
use App\Support\WinlogClassifier;
use Carbon\CarbonImmutable;

/** Mengubah entri Windows Event Log dari agent menjadi event di Event Viewer (diklasifikasi, tanpa duplikat). */
final class WinlogRecorder
{
    private const MAX_AGE_DAYS = 7;
    private const LOGS = ['System', 'Application'];

    public function __construct(private readonly EventRecorder $events)
    {
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array{stored: int, skipped: int}
     */
    public function record(Device $device, array $entries): array
    {
        $now = CarbonImmutable::now();
        $valid = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $time = isset($entry['time']) && is_numeric($entry['time']) ? CarbonImmutable::createFromTimestampUTC((int) $entry['time']) : null;

            if (! in_array($entry['log'] ?? null, self::LOGS, true) || $time === null
                || $time->gt($now->addMinutes(5)) || $time->lt($now->subDays(self::MAX_AGE_DAYS))) {
                continue;
            }

            $valid[] = $entry + ['_at' => $time];
        }

        if ($valid === []) {
            return ['stored' => 0, 'skipped' => count($entries)];
        }

        $known = $this->knownRecords($device, min(array_map(static fn (array $e): CarbonImmutable => $e['_at'], $valid)));
        $stored = 0;

        foreach ($valid as $entry) {
            $key = $entry['log'].'|'.(int) $entry['record'];

            if (isset($known[$key])) {
                continue; // kiriman ulang batch yang sama
            }

            $known[$key] = true;
            $provider = mb_substr((string) $entry['provider'], 0, 200);
            $count = max(1, (int) ($entry['count'] ?? 1));
            $message = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) ($entry['message'] ?? '')));
            $text = "[{$provider} #{$entry['id']}] ".mb_substr($message !== '' ? $message : '(tanpa pesan)', 0, 300).($count > 1 ? " (×{$count})" : '');

            $this->events->record(
                'winlog.event', $text, $device, null,
                ['log' => $entry['log'], 'provider' => $provider, 'event_id' => (int) $entry['id'], 'record_id' => (int) $entry['record'], 'level' => (int) $entry['level'], 'count' => $count],
                $entry['_at'], WinlogClassifier::severity((int) $entry['level']), WinlogClassifier::category($provider),
            );
            $stored++;
        }

        return ['stored' => $stored, 'skipped' => count($entries) - $stored];
    }

    /** @return array<string, true> kunci "log|record" yang sudah tercatat sejak waktu terawal batch ini */
    private function knownRecords(Device $device, CarbonImmutable $since): array
    {
        return Event::query()->where('device_id', $device->id)->where('code', 'winlog.event')
            ->where('occurred_at', '>=', $since->subMinute())->get(['details'])
            ->mapWithKeys(static fn (Event $e): array => [($e->details['log'] ?? '').'|'.($e->details['record_id'] ?? '') => true])->all();
    }
}
