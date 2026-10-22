<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CommandStatus;
use App\Models\AuditLog;
use App\Models\BrowsingDaily;
use App\Models\DeviceCommand;
use App\Models\DeviceHeartbeat;
use App\Models\DeviceStatusHistory;
use App\Models\Event;
use App\Models\InterfaceSample;
use App\Models\MonitorTargetCheck;
use Illuminate\Database\Eloquent\Builder;

final class RetentionService
{
    private const DELETE_CHUNK = 10_000;

    public function __construct(private readonly BrowsingRollup $rollup)
    {
    }

    /**
     * Hapus data lama sesuai config('netwatch.retention').
     *
     * @return array{heartbeats: int, commands: int, audit_logs: int, status_history: int, bandwidth: int, events: int, browsing: int, browsing_daily: int, monitor_checks: int}
     */
    public function prune(): array
    {
        $retention = config('netwatch.retention');

        return [
            'heartbeats' => $this->deleteInChunks(
                DeviceHeartbeat::query()->where('recorded_at', '<', now()->subDays($retention['heartbeats_days']))
            ),
            'bandwidth' => $this->deleteInChunks(
                InterfaceSample::query()->where('sampled_at', '<', now()->subDays($retention['bandwidth_days']))
            ),
            // Data mentah dirangkum menjadi ringkasan harian sebelum dihapus; ringkasan disimpan jauh lebih lama.
            'browsing' => $this->rollup->run(now()->subDays($retention['browsing_days']))['deleted'],
            'browsing_daily' => $this->deleteInChunks(
                BrowsingDaily::query()->where('day', '<', now()->subDays($retention['browsing_daily_days'])->toDateString())
            ),
            'monitor_checks' => $this->deleteInChunks(
                MonitorTargetCheck::query()->where('checked_at', '<', now()->subDays($retention['monitor_checks_days']))
            ),
            'events' => $this->deleteInChunks(
                Event::query()->where('occurred_at', '<', now()->subDays($retention['events_days']))
            ),
            'commands' => $this->deleteInChunks(
                DeviceCommand::query()
                    ->whereIn('status', [
                        CommandStatus::Succeeded->value,
                        CommandStatus::Failed->value,
                        CommandStatus::Expired->value,
                    ])
                    ->where('created_at', '<', now()->subDays($retention['commands_days']))
            ),
            'audit_logs' => $this->deleteInChunks(
                AuditLog::query()->where('created_at', '<', now()->subDays($retention['audit_days']))
            ),
            // Hanya interval yang sudah selesai; interval terbuka (status saat ini) tidak pernah dihapus.
            'status_history' => $this->deleteInChunks(
                DeviceStatusHistory::query()
                    ->whereNotNull('ended_at')
                    ->where('ended_at', '<', now()->subDays($retention['status_history_days']))
            ),
        ];
    }

    /**
     * Hapus per potongan agar tidak mengunci tabel besar terlalu lama.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function deleteInChunks(Builder $query): int
    {
        $total = 0;

        do {
            $ids = (clone $query)->limit(self::DELETE_CHUNK)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $total += $query->getModel()->newQuery()->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::DELETE_CHUNK);

        return $total;
    }
}
