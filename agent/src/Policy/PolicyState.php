<?php

declare(strict_types=1);

namespace NetWatch\Agent\Policy;

/** Status penerapan kebijakan yang bertahan antar-restart agent (disimpan berdampingan dengan agent.json). */
final class PolicyState
{
    public function __construct(
        public readonly ?string $hash = null,
        public readonly ?string $error = null,
        public readonly ?string $attemptedHash = null,
        public readonly int $attemptedAt = 0,
    ) {
    }

    public static function load(string $path): self
    {
        $data = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (!is_array($data)) {
            return new self();
        }

        return new self(
            is_string($data['hash'] ?? null) ? $data['hash'] : null,
            is_string($data['error'] ?? null) ? $data['error'] : null,
            is_string($data['attempted_hash'] ?? null) ? $data['attempted_hash'] : null,
            (int) ($data['attempted_at'] ?? 0),
        );
    }

    /** Simpan atomik dengan izin ketat. Kegagalan menyimpan tidak fatal: kebijakan hanya diterapkan ulang. */
    public function save(string $path): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return;
        }

        $temporary = $path . '.tmp';
        $json = json_encode([
            'hash' => $this->hash,
            'error' => $this->error,
            'attempted_hash' => $this->attemptedHash,
            'attempted_at' => $this->attemptedAt,
        ], JSON_THROW_ON_ERROR);

        if (@file_put_contents($temporary, $json, LOCK_EX) !== false) {
            @chmod($temporary, 0600);
            @rename($temporary, $path);
        }
    }
}
