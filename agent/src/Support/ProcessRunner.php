<?php

declare(strict_types=1);

namespace NetWatch\Agent\Support;

use RuntimeException;

/**
 * Menjalankan program eksternal TANPA shell: argumen dikirim sebagai array sehingga
 * input (mis. nama proses atau pesan) tidak bisa disisipi perintah.
 * Output ditulis ke berkas sementara karena stream_select tidak andal untuk pipe di Windows.
 */
final class ProcessRunner
{
    private const MAX_OUTPUT_BYTES = 8_388_608;
    private const POLL_INTERVAL_US = 50_000;

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     *
     * @throws RuntimeException bila program tidak bisa dijalankan atau melewati batas waktu
     */
    public function run(array $command, ?string $stdin = null, array $env = [], int $timeoutSeconds = 20): ProcessResult
    {
        $stdoutFile = $this->tempFile();
        $stderrFile = $this->tempFile();

        try {
            $process = proc_open(
                $command,
                [0 => ['pipe', 'r'], 1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']],
                $pipes,
                null,
                $env === [] ? null : array_merge(getenv(), $env),
                ['bypass_shell' => true],
            );

            if (!is_resource($process)) {
                throw new RuntimeException('Tidak dapat menjalankan: ' . $command[0]);
            }

            if ($stdin !== null) {
                fwrite($pipes[0], $stdin);
            }
            fclose($pipes[0]);

            $exitCode = $this->waitFor($process, $timeoutSeconds, $command[0]);

            return new ProcessResult(
                $exitCode,
                (string) file_get_contents($stdoutFile, false, null, 0, self::MAX_OUTPUT_BYTES),
                (string) file_get_contents($stderrFile, false, null, 0, self::MAX_OUTPUT_BYTES),
            );
        } finally {
            @unlink($stdoutFile);
            @unlink($stderrFile);
        }
    }

    /** @param  resource  $process */
    private function waitFor($process, int $timeoutSeconds, string $program): int
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $status = proc_get_status($process);

            if (!$status['running']) {
                $closed = proc_close($process);

                return $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
            }

            usleep(self::POLL_INTERVAL_US);
        } while (microtime(true) < $deadline);

        proc_terminate($process);
        proc_close($process);

        throw new RuntimeException("Waktu habis ({$timeoutSeconds} dtk) menjalankan: {$program}");
    }

    private function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nwa');

        if ($path === false) {
            throw new RuntimeException('Tidak dapat membuat berkas sementara.');
        }

        return $path;
    }
}
