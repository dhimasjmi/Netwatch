<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConnectionStatus;
use App\Models\Device;
use App\Models\DeviceStatusHistory;
use App\Support\DateRange;
use App\Support\StatusInterval;
use App\Support\UptimeCalculator;
use App\Support\UptimeSummary;
use Illuminate\Support\Collection;

final class UptimeReportService
{
    private const MAX_OUTAGE_ROWS = 200;

    /**
     * @return array{summary: UptimeSummary, segments: list<array{down: bool, start: int, end: int}>, outages: Collection<int, DeviceStatusHistory>}
     */
    public function forDevice(Device $device, DateRange $range): array
    {
        $rows = DeviceStatusHistory::query()
            ->where('device_id', $device->id)
            ->overlapping($range->from, $range->to)
            ->orderBy('started_at')
            ->get();

        $intervals = $this->toIntervals($rows);
        $from = $range->from->getTimestamp();
        $to = $range->to->getTimestamp();

        return [
            'summary' => UptimeCalculator::summarize($intervals, $from, $to),
            'segments' => UptimeCalculator::segments($intervals, $from, $to),
            'outages' => $rows->where('status', ConnectionStatus::Offline)->sortByDesc('started_at')->take(self::MAX_OUTAGE_ROWS)->values(),
        ];
    }

    /**
     * Ringkasan ketersediaan semua perangkat aktif, terburuk lebih dulu.
     *
     * @return list<array{device: Device, summary: UptimeSummary}>
     */
    public function fleet(DateRange $range, ?int $groupId = null): array
    {
        $devices = Device::query()->active()->with('group')
            ->when($groupId !== null, fn ($q) => $q->where('device_group_id', $groupId))
            ->orderBy('hostname')
            ->get();

        $history = DeviceStatusHistory::query()
            ->whereIn('device_id', $devices->modelKeys())
            ->overlapping($range->from, $range->to)
            ->get()
            ->groupBy('device_id');

        $from = $range->from->getTimestamp();
        $to = $range->to->getTimestamp();

        $rows = $devices->map(fn (Device $device): array => [
            'device' => $device,
            'summary' => UptimeCalculator::summarize($this->toIntervals($history->get($device->id, collect())), $from, $to),
        ])->all();

        // Perangkat tanpa data (null) di akhir; sisanya dari ketersediaan terendah.
        usort($rows, static fn (array $a, array $b): int => ($a['summary']->availabilityPercent() ?? 101.0) <=> ($b['summary']->availabilityPercent() ?? 101.0));

        return $rows;
    }

    /**
     * @param  Collection<int, DeviceStatusHistory>  $rows
     * @return list<StatusInterval>
     */
    private function toIntervals(Collection $rows): array
    {
        return $rows->map(static fn (DeviceStatusHistory $row): StatusInterval => new StatusInterval(
            $row->status === ConnectionStatus::Offline,
            $row->started_at->getTimestamp(),
            $row->ended_at?->getTimestamp(),
        ))->values()->all();
    }
}
