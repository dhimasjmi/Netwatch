<?php

declare(strict_types=1);

namespace NetWatch\Agent\Winlog;

/** Posisi terakhir pembacaan per log (waktu Unix dan nomor record), disimpan agar bertahan antar-restart. */
final class WinlogState
{
    /** @param  array<string, array{time: int, record: int}>  $cursors */
    public function __construct(public array $cursors = [])
    {
    }

    public static function load(string $path): self
    {
        $data = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
        $cursors = [];

        foreach (is_array($data['cursors'] ?? null) ? $data['cursors'] : [] as $log => $cursor) {
            if (is_string($log) && is_array($cursor) && is_int($cursor['time'] ?? null) && is_int($cursor['record'] ?? null)) {
                $cursors[$log] = ['time' => $cursor['time'], 'record' => $cursor['record']];
            }
        }

        return new self($cursors);
    }

    public function save(string $path): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return;
        }

        $temporary = $path . '.tmp';

        if (@file_put_contents($temporary, json_encode(['cursors' => $this->cursors], JSON_THROW_ON_ERROR), LOCK_EX) !== false) {
            @chmod($temporary, 0600);
            @rename($temporary, $path);
        }
    }
}
