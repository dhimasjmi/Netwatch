<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BackupService;
use App\Services\EventRecorder;
use Illuminate\Console\Command;
use RuntimeException;

final class BackupCommand extends Command
{
    protected $signature = 'netwatch:backup {--verify= : Periksa satu berkas backup (nama atau path) tanpa membuat backup baru}';

    protected $description = 'Backup database (pg_dump) ke folder backup, lengkap dengan checksum, salinan kedua, dan pembersihan backup lama';

    public function handle(BackupService $backup, EventRecorder $events): int
    {
        if (is_string($path = $this->option('verify')) && $path !== '') {
            return $this->verify($backup, $path);
        }

        if (! config('netwatch.backup.enabled')) {
            $this->warn('Backup dinonaktifkan (NETWATCH_BACKUP_ENABLED=false).');

            return self::SUCCESS;
        }

        try {
            $r = $backup->run();
        } catch (RuntimeException $e) {
            $this->error('Backup gagal: '.$e->getMessage());
            $events->record('backup.failed', 'Backup database gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Backup selesai: %s (%.1f MB, %.1f detik)%s; %d backup lama dihapus.',
            $r['file'], $r['bytes'] / 1048576, $r['seconds'], $r['copied'] ? ', salinan kedua tersimpan' : '', $r['pruned'],
        ));

        return self::SUCCESS;
    }

    private function verify(BackupService $backup, string $path): int
    {
        $full = is_file($path) ? $path : $backup->directory().DIRECTORY_SEPARATOR.$path;

        try {
            $backup->verify($full);
        } catch (RuntimeException $e) {
            $this->error('Backup TIDAK valid: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info('Backup valid: checksum cocok dan isinya terbaca.');

        return self::SUCCESS;
    }
}
