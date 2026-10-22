<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CheckStatus;
use App\Services\SecurityChecker;
use Illuminate\Console\Command;

final class SecurityCheckCommand extends Command
{
    protected $signature = 'netwatch:security-check';

    protected $description = 'Periksa konfigurasi dan kondisi armada dari sisi keamanan (kode keluar 1 bila ada yang berisiko)';

    public function handle(SecurityChecker $checker): int
    {
        $checks = $checker->run();

        $this->table(
            ['Pemeriksaan', 'Status', 'Keterangan'],
            array_map(fn ($c): array => [$c->title, $c->status->label(), $c->detail], $checks),
        );

        foreach ($checks as $check) {
            if ($check->advice !== null && $check->status !== CheckStatus::Ok) {
                $this->line("- {$check->title}: {$check->advice}");
            }
        }

        return SecurityChecker::worst($checks) === CheckStatus::Fail ? self::FAILURE : self::SUCCESS;
    }
}
