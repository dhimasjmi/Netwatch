<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RetentionService;
use Illuminate\Console\Command;

final class PruneDataCommand extends Command
{
    protected $signature = 'netwatch:prune';

    protected $description = 'Hapus heartbeat, perintah, dan audit log yang melewati masa retensi';

    public function handle(RetentionService $retention): int
    {
        foreach ($retention->prune() as $table => $deleted) {
            $this->line(sprintf('%-12s %d baris dihapus', $table, $deleted));
        }

        return self::SUCCESS;
    }
}
