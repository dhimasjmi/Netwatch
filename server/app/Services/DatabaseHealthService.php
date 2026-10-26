<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Ukuran, kesegaran retensi, dan status backup database, untuk halaman "Database & Backup" dan `netwatch:db-stats`. */
final class DatabaseHealthService
{
    /** label, tabel, kolom waktu, kunci retensi (hari) */
    private const SERIES = [
        ['Heartbeat', 'device_heartbeats', 'recorded_at', 'heartbeats_days'],
        ['Bandwidth mentah', 'interface_samples', 'sampled_at', 'bandwidth_raw_days'],
        ['Bandwidth per jam', 'interface_samples_hourly', 'hour', 'bandwidth_days'],
        ['Browsing mentah', 'browsing_visits', 'visited_at', 'browsing_days'],
        ['Browsing harian', 'browsing_daily', 'day', 'browsing_daily_days'],
        ['Cek jaringan', 'monitor_target_checks', 'checked_at', 'monitor_checks_days'],
        ['Event', 'events', 'occurred_at', 'events_days'],
        ['Audit log', 'audit_logs', 'created_at', 'audit_days'],
    ];

    /** Pembersihan dianggap tertinggal bila data tertua melewati retensi lebih dari ini (hari). */
    private const PRUNE_GRACE_DAYS = 2;

    public function __construct(private readonly BackupService $backups)
    {
    }

    /** @return array<string, mixed> */
    public function report(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $warnings = [];

        $series = [];
        foreach (self::SERIES as [$label, $table, $column, $key]) {
            $days = (int) config("netwatch.retention.{$key}");
            $oldest = DB::table($table)->min($column);
            $oldestAt = $oldest !== null ? CarbonImmutable::parse($oldest, 'UTC') : null;
            $behind = $oldestAt !== null && $oldestAt->lt($now->subDays($days + self::PRUNE_GRACE_DAYS));
            if ($behind) {
                $warnings[] = "Pembersihan {$label} tertinggal: data tertua {$oldestAt->diffInDays($now)} hari, retensi {$days} hari. Pastikan scheduler (netwatch:prune) berjalan.";
            }
            $series[] = ['label' => $label, 'table' => $table, 'retention_days' => $days, 'oldest' => $oldestAt, 'behind' => $behind];
        }

        $backup = $this->backupStatus($now, $warnings);

        return [
            'size_bytes' => (int) DB::scalar('select pg_database_size(current_database())'),
            'tables' => $this->tables(),
            'series' => $series,
            'backup' => $backup,
            'warnings' => $warnings,
        ];
    }

    /** @return list<array{name: string, total_bytes: int, rows: int, dead: int, last_vacuum: ?string}> */
    private function tables(): array
    {
        return array_map(fn (object $r): array => [
            'name' => (string) $r->name, 'total_bytes' => (int) $r->total, 'rows' => (int) $r->rows, 'dead' => (int) $r->dead, 'last_vacuum' => $r->last_vacuum,
        ], DB::select(<<<'SQL'
            SELECT relname AS name, pg_total_relation_size(relid) AS total, n_live_tup AS rows, n_dead_tup AS dead,
                   coalesce(last_autovacuum, last_vacuum) AS last_vacuum
            FROM pg_stat_user_tables ORDER BY pg_total_relation_size(relid) DESC LIMIT 15
            SQL));
    }

    /**
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function backupStatus(CarbonImmutable $now, array &$warnings): array
    {
        $enabled = (bool) config('netwatch.backup.enabled');
        $files = $this->backups->list();
        $latest = $files[0] ?? null;
        $copyTo = config('netwatch.backup.copy_to');
        $free = @disk_free_space($this->backups->directory());

        if (! $enabled) {
            $warnings[] = 'Backup otomatis dinonaktifkan (NETWATCH_BACKUP_ENABLED=false).';
        } elseif ($latest === null) {
            $warnings[] = 'Belum ada backup. Jalankan "php artisan netwatch:backup" dan pastikan scheduler aktif.';
        } elseif ($latest['time'] < $now->subHours((int) config('netwatch.backup.stale_after_hours'))->getTimestamp()) {
            $warnings[] = 'Backup terakhir sudah lebih dari '.(int) config('netwatch.backup.stale_after_hours').' jam. Periksa scheduler dan event "backup.failed".';
        }
        if (! is_string($copyTo) || $copyTo === '') {
            $warnings[] = 'Belum ada salinan kedua backup (NETWATCH_BACKUP_COPY_TO). Bila disk server rusak, backup di disk yang sama ikut hilang.';
        }

        return [
            'enabled' => $enabled,
            'directory' => $this->backups->directory(),
            'copy_to' => is_string($copyTo) && $copyTo !== '' ? $copyTo : null,
            'files' => array_slice($files, 0, 10),
            'count' => count($files),
            'latest_at' => $latest !== null ? CarbonImmutable::createFromTimestampUTC($latest['time']) : null,
            'free_bytes' => $free === false ? null : (int) $free,
        ];
    }
}
