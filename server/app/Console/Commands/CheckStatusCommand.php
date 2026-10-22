<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConnectionStatus;
use App\Models\Device;
use App\Services\StatusTracker;
use Illuminate\Console\Command;

final class CheckStatusCommand extends Command
{
    protected $signature = 'netwatch:check-status';

    protected $description = 'Tandai perangkat yang berhenti mengirim heartbeat sebagai offline dan catat awal gangguannya';

    public function handle(StatusTracker $tracker): int
    {
        $threshold = now()->subSeconds((int) config('netwatch.offline_after'));
        $marked = 0;

        Device::query()->active()
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<=', $threshold)
            ->whereHas('statusHistory', fn ($q) => $q->whereNull('ended_at')->where('status', ConnectionStatus::Online->value))
            ->chunkById(200, function ($devices) use ($tracker, &$marked): void {
                foreach ($devices as $device) {
                    // Gangguan dihitung sejak heartbeat terakhir, bukan sejak pengecekan ini berjalan.
                    $tracker->markOffline($device, $device->last_seen_at);
                    $marked++;
                }
            });

        $this->info("Perangkat ditandai offline: {$marked}");

        return self::SUCCESS;
    }
}
