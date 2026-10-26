<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\BackupRetention;
use App\Support\PgDumpCommand;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Backup database dengan pg_dump (format custom, terkompresi): berkas ditulis sebagai .partial lalu diganti nama
 * hanya bila pg_dump sukses dan isinya terbaca oleh pg_restore, sehingga backup setengah jadi tidak pernah tampak valid.
 */
final class BackupService
{
    private const MIN_FREE_FACTOR = 1.5;

    public function directory(): string
    {
        $configured = config('netwatch.backup.path');

        return rtrim(is_string($configured) && $configured !== '' ? $configured : storage_path('app/backups'), '/\\');
    }

    /**
     * @return array{file: string, path: string, bytes: int, sha256: string, seconds: float, copied: bool, pruned: int}
     *
     * @throws RuntimeException bila backup gagal (pesan aman ditampilkan; kata sandi tidak pernah ikut)
     */
    public function run(?int $now = null): array
    {
        $now ??= time();
        $started = microtime(true);
        $dir = $this->directory();
        $this->ensureDirectory($dir);
        $this->ensureFreeSpace($dir);

        $name = BackupRetention::nameFor($now);
        $final = $dir.DIRECTORY_SEPARATOR.$name;
        $partial = $final.'.partial';
        $db = (array) config('database.connections.pgsql');

        try {
            $this->exec(PgDumpCommand::dump((string) config('netwatch.backup.pg_dump'), $db, $partial, (array) config('netwatch.backup.exclude_data')), PgDumpCommand::environment($db), 'pg_dump');
            $this->verifyReadable($partial);

            $bytes = (int) filesize($partial);
            $sha = (string) hash_file('sha256', $partial);
            if (! rename($partial, $final)) {
                throw new RuntimeException('Gagal memindahkan berkas backup ke tempat akhir.');
            }
        } finally {
            if (is_file($partial)) {
                @unlink($partial);
            }
        }

        file_put_contents($final.'.sha256', $sha.'  '.$name.PHP_EOL);
        @chmod($final, 0600);

        $copied = $this->copyOffsite($final, $name, $sha);
        $pruned = $this->prune($dir) + ($copied ? $this->prune((string) config('netwatch.backup.copy_to')) : 0);

        return ['file' => $name, 'path' => $final, 'bytes' => $bytes, 'sha256' => $sha, 'seconds' => round(microtime(true) - $started, 1), 'copied' => $copied, 'pruned' => $pruned];
    }

    /** @return list<array{name: string, bytes: int, time: int}> terbaru dulu */
    public function list(?string $dir = null): array
    {
        $dir ??= $this->directory();
        $out = [];
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
            $time = BackupRetention::timeFromName($name);
            if ($time !== null && is_file($dir.DIRECTORY_SEPARATOR.$name)) {
                $out[] = ['name' => $name, 'bytes' => (int) filesize($dir.DIRECTORY_SEPARATOR.$name), 'time' => $time];
            }
        }
        usort($out, fn (array $a, array $b): int => $b['time'] <=> $a['time']);

        return $out;
    }

    /** Memeriksa berkas backup tanpa memulihkannya: checksum cocok dan pg_restore bisa membaca daftar isinya. */
    public function verify(string $path): void
    {
        $sumFile = $path.'.sha256';
        if (is_file($sumFile)) {
            $expected = strtolower(substr(trim((string) file_get_contents($sumFile)), 0, 64));
            if (! hash_equals($expected, (string) hash_file('sha256', $path))) {
                throw new RuntimeException('Checksum tidak cocok: berkas rusak atau berubah.');
            }
        }
        $this->verifyReadable($path);
    }

    private function verifyReadable(string $path): void
    {
        if (! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('Berkas backup kosong.');
        }
        $output = $this->exec(PgDumpCommand::listContents((string) config('netwatch.backup.pg_restore'), $path), [], 'pg_restore --list');
        if (! str_contains($output, ' TABLE public users ') && ! str_contains($output, ' TABLE DATA public users ')) {
            throw new RuntimeException('Isi backup tidak lengkap (tabel users tidak ditemukan).');
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Folder backup tidak bisa dibuat: {$dir}");
        }
        if (! is_writable($dir)) {
            throw new RuntimeException("Folder backup tidak bisa ditulis: {$dir}");
        }
    }

    private function ensureFreeSpace(string $dir): void
    {
        $free = @disk_free_space($dir);
        if ($free === false) {
            return; // tidak bisa diukur (mis. share jaringan tertentu); pg_dump akan gagal sendiri bila penuh
        }
        $latest = $this->list($dir)[0]['bytes'] ?? 0;
        $need = max((int) config('netwatch.backup.min_free_mb') * 1048576, (int) ($latest * self::MIN_FREE_FACTOR));
        if ($free < $need) {
            throw new RuntimeException(sprintf('Ruang disk kurang untuk backup: tersisa %d MB, dibutuhkan sekitar %d MB.', (int) ($free / 1048576), (int) ($need / 1048576)));
        }
    }

    private function copyOffsite(string $file, string $name, string $sha): bool
    {
        $target = config('netwatch.backup.copy_to');
        if (! is_string($target) || $target === '') {
            return false;
        }
        $target = rtrim($target, '/\\');
        $this->ensureDirectory($target);

        $dest = $target.DIRECTORY_SEPARATOR.$name;
        if (! copy($file, $dest.'.partial') || ! rename($dest.'.partial', $dest)) {
            @unlink($dest.'.partial');
            throw new RuntimeException("Backup berhasil tetapi salinan kedua gagal ditulis ke {$target}.");
        }
        if (! hash_equals($sha, (string) hash_file('sha256', $dest))) {
            @unlink($dest);
            throw new RuntimeException('Salinan kedua rusak (checksum berbeda) dan dihapus.');
        }
        file_put_contents($dest.'.sha256', $sha.'  '.$name.PHP_EOL);

        return true;
    }

    private function prune(string $dir): int
    {
        $files = [];
        foreach ($this->list($dir) as $f) {
            $files[$f['name']] = $f['time'];
        }
        $deleted = 0;
        foreach (BackupRetention::obsolete($files, (int) config('netwatch.backup.keep_daily'), (int) config('netwatch.backup.keep_weekly'), (int) config('netwatch.backup.keep_monthly')) as $name) {
            @unlink($dir.DIRECTORY_SEPARATOR.$name.'.sha256');
            $deleted += @unlink($dir.DIRECTORY_SEPARATOR.$name) ? 1 : 0;
        }

        return $deleted;
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private function exec(array $command, array $env, string $label): string
    {
        $process = new Process($command, null, $env === [] ? null : $env, null, (float) config('netwatch.backup.timeout_seconds'));

        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new RuntimeException("{$label} tidak bisa dijalankan: periksa path di NETWATCH_PG_DUMP / NETWATCH_PG_RESTORE.", 0, $e);
        }
        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput());

            throw new RuntimeException("{$label} gagal (kode {$process->getExitCode()}): ".mb_substr($error !== '' ? $error : 'tanpa pesan', 0, 400));
        }

        return $process->getOutput();
    }
}
