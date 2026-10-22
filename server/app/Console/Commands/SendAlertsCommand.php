<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AlertDispatcher;
use Illuminate\Console\Command;

final class SendAlertsCommand extends Command
{
    protected $signature = 'netwatch:send-alerts';

    protected $description = 'Kirim notifikasi (Telegram/email) untuk event offline/online yang belum diberitahukan';

    public function handle(AlertDispatcher $alerts): int
    {
        $result = $alerts->run();

        $this->info("Terkirim: {$result['sent']}, dilewati: {$result['skipped']}");
        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
