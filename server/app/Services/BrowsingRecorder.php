<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BrowsingVisit;
use App\Models\Device;
use App\Support\BrowsingEntry;
use Carbon\CarbonImmutable;

final class BrowsingRecorder
{
    private const CHUNK = 200;

    public function __construct(private readonly BrowsingIgnoreList $ignored)
    {
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{stored: int, rejected: int, ignored: int, over_limit: int}
     */
    public function record(Device $device, array $items): array
    {
        $detail = config('netwatch.browsing.detail') === 'path' ? 'path' : 'domain';
        $now = CarbonImmutable::now();
        $rows = [];
        $ignored = 0;

        foreach ($items as $item) {
            $row = is_array($item) ? BrowsingEntry::normalize($item, $detail, $now) : null;
            if ($row === null) {
                continue;
            }
            if ($this->ignored->isIgnored($row['host'])) {
                $ignored++;

                continue;
            }
            // Kunci dedup sama dalam satu batch hanya disimpan sekali.
            $rows[$row['dedupe_hash']] = ['device_id' => $device->id] + $row;
        }

        // Pengaman volume: satu perangkat tidak boleh menyimpan lebih dari batas per 24 jam.
        $room = max(0, (int) config('netwatch.browsing.max_per_device_day') - BrowsingVisit::query()
            ->where('device_id', $device->id)->where('visited_at', '>=', $now->subDay())->count());
        $overLimit = max(0, count($rows) - $room);
        $rows = array_slice($rows, 0, $room, true);

        $stored = 0;
        foreach (array_chunk(array_values($rows), self::CHUNK) as $chunk) {
            $stored += BrowsingVisit::query()->insertOrIgnore($chunk);
        }

        return ['stored' => $stored, 'rejected' => count($items) - count($rows) - $ignored - $overLimit, 'ignored' => $ignored, 'over_limit' => $overLimit];
    }
}
