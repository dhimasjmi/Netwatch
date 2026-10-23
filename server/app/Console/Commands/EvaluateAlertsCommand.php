<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Feature;
use App\Services\AlertEvaluator;
use App\Services\FeatureFlags;
use Illuminate\Console\Command;

final class EvaluateAlertsCommand extends Command
{
    protected $signature = 'netwatch:evaluate-alerts';

    protected $description = 'Evaluasi aturan alert ambang (CPU, RAM, disk) terhadap riwayat heartbeat.';

    public function handle(AlertEvaluator $evaluator, FeatureFlags $features): int
    {
        if (! $features->enabled(Feature::ThresholdAlerts)) {
            return self::SUCCESS;
        }

        $result = $evaluator->run();
        $this->info("Alert menyala: {$result['fired']}, pulih: {$result['resolved']}.");

        return self::SUCCESS;
    }
}
