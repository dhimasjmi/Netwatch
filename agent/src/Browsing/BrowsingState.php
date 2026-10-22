<?php

declare(strict_types=1);

namespace NetWatch\Agent\Browsing;

/** Posisi terakhir pembacaan riwayat per berkas browser, disimpan agar bertahan antar-restart. */
final class BrowsingState
{
    /** @param  array<string, int>  $cursors kunci "browser|path" => waktu Chrome terakhir yang sudah terkirim */
    public function __construct(public array $cursors = [])
    {
    }

    public static function load(string $path): self
    {
        $data = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
        $cursors = [];

        foreach (is_array($data['cursors'] ?? null) ? $data['cursors'] : [] as $key => $value) {
            if (is_string($key) && is_int($value)) {
                $cursors[$key] = $value;
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
