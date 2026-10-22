<?php

declare(strict_types=1);

namespace NetWatch\Agent\Support;

/** Logger file sederhana dengan rotasi. Jangan pernah mencatat token atau isi pesan sensitif. */
final class Logger
{
    private const MAX_BYTES = 5_242_880;

    public function __construct(
        private readonly ?string $file = null,
        private readonly bool $echoToStderr = false,
    ) {
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function warning(string $message): void
    {
        $this->write('WARN', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    private function write(string $level, string $message): void
    {
        $line = sprintf("[%s] %-5s %s\n", gmdate('Y-m-d\TH:i:s\Z'), $level, $message);

        if ($this->echoToStderr) {
            fwrite(STDERR, $line);
        }

        if ($this->file === null) {
            return;
        }

        $this->rotateIfNeeded();

        // Kegagalan menulis log tidak boleh menjatuhkan agent, jadi hasilnya sengaja diabaikan.
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }

    private function rotateIfNeeded(): void
    {
        clearstatcache(true, (string) $this->file);

        if (is_file((string) $this->file) && filesize((string) $this->file) > self::MAX_BYTES) {
            @rename((string) $this->file, $this->file . '.1');
        }
    }
}
