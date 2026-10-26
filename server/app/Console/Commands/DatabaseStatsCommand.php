<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DatabaseHealthService;
use Illuminate\Console\Command;

final class DatabaseStatsCommand extends Command
{
    protected $signature = 'netwatch:db-stats';

    protected $description = 'Tampilkan ukuran database, tabel terbesar, kesegaran retensi, dan status backup';

    public function handle(DatabaseHealthService $health): int
    {
        $r = $health->report();

        $this->line(sprintf('Ukuran database: %.1f MB', $r['size_bytes'] / 1048576));
        $this->table(['Tabel', 'Ukuran (MB)', 'Perkiraan baris', 'Baris mati'], array_map(
            fn (array $t): array => [$t['name'], round($t['total_bytes'] / 1048576, 1), number_format($t['rows']), number_format($t['dead'])], $r['tables'],
        ));
        $this->table(['Data', 'Retensi (hari)', 'Data tertua', 'Pembersihan'], array_map(
            fn (array $s): array => [$s['label'], $s['retention_days'], $s['oldest']?->format('Y-m-d H:i') ?? '—', $s['behind'] ? 'TERTINGGAL' : 'ok'], $r['series'],
        ));
        $b = $r['backup'];
        $this->line(sprintf('Backup: %d berkas di %s; terakhir %s', $b['count'], $b['directory'], $b['latest_at']?->format('Y-m-d H:i').' UTC' ?? 'belum ada'));

        foreach ($r['warnings'] as $warning) {
            $this->warn($warning);
        }

        return $r['warnings'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
