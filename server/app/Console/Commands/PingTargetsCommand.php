<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Feature;
use App\Services\FeatureFlags;
use App\Services\TargetMonitorService;
use Illuminate\Console\Command;

final class PingTargetsCommand extends Command
{
    protected $signature = 'netwatch:ping-targets';

    protected $description = 'Ping/cek port perangkat jaringan yang dipantau dari server (Network Operations Center)';

    public function handle(TargetMonitorService $monitor, FeatureFlags $features): int
    {
        if (! $features->enabled(Feature::Network)) {
            return self::SUCCESS;
        }

        $this->info('Target dicek: '.$monitor->runAll());

        return self::SUCCESS;
    }
}
