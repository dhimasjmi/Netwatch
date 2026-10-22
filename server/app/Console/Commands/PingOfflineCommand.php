<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PingService;
use Illuminate\Console\Command;

final class PingOfflineCommand extends Command
{
    protected $signature = 'netwatch:ping-offline';

    protected $description = 'Ping perangkat yang offline dari server untuk membedakan PC mati dan agent berhenti';

    public function handle(PingService $ping): int
    {
        if (!config('netwatch.ping.enabled')) {
            return self::SUCCESS;
        }

        $this->info('Perangkat di-ping: '.$ping->run());

        return self::SUCCESS;
    }
}
