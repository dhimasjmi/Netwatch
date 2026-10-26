<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BandwidthRollup;
use Illuminate\Console\Command;

final class RollupBandwidthCommand extends Command
{
    protected $signature = 'netwatch:rollup-bandwidth';

    protected $description = 'Rangkum sampel bandwidth mentah menjadi ringkasan per jam (dijadwalkan tiap jam)';

    public function handle(BandwidthRollup $rollup): int
    {
        $result = $rollup->run();
        $this->line(sprintf('%d jam dirangkum, %d sampel mentah lama dihapus', $result['rolled_hours'], $result['deleted']));

        return self::SUCCESS;
    }
}
