<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CommandService;
use Illuminate\Console\Command;

final class ExpireCommandsCommand extends Command
{
    protected $signature = 'netwatch:expire-commands';

    protected $description = 'Tandai perintah yang tidak diambil/diselesaikan agent sebagai kedaluwarsa';

    public function handle(CommandService $commands): int
    {
        $count = $commands->expireStale();
        $this->info("Perintah diperbarui: {$count}");

        return self::SUCCESS;
    }
}
