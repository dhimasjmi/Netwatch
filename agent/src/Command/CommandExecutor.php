<?php

declare(strict_types=1);

namespace NetWatch\Agent\Command;

use NetWatch\Agent\Platform\PlatformException;
use NetWatch\Agent\Platform\PlatformInterface;
use NetWatch\Agent\Support\ProtectedProcesses;

/**
 * Menjalankan perintah dari server. Hanya tipe yang di-whitelist di bawah yang diproses;
 * tipe lain ditolak, sehingga server yang diretas pun tidak bisa meminta eksekusi shell bebas.
 */
final class CommandExecutor
{
    private const MAX_POWER_DELAY_SECONDS = 3600;
    private const MAX_MESSAGE_LENGTH = 500;

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly ProcessReporter $reporter,
    ) {
    }

    /** @param  array<string, mixed>  $payload */
    public function execute(string $type, array $payload): CommandOutcome
    {
        try {
            return match ($type) {
                'refresh_processes' => $this->refreshProcesses(),
                'terminate_process' => $this->terminateProcess($payload),
                'send_message' => $this->sendMessage($payload),
                'restart_computer' => $this->power('restart', $payload),
                'shutdown_computer' => $this->power('shutdown', $payload),
                default => CommandOutcome::failure("Tipe perintah tidak dikenal: {$type}"),
            };
        } catch (PlatformException|\RuntimeException $e) {
            return CommandOutcome::failure($e->getMessage());
        }
    }

    private function refreshProcesses(): CommandOutcome
    {
        return CommandOutcome::success(['process_count' => $this->reporter->report()]);
    }

    /** @param  array<string, mixed>  $payload */
    private function terminateProcess(array $payload): CommandOutcome
    {
        $pid = filter_var($payload['pid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2]]);
        $expectedName = trim((string) ($payload['process_name'] ?? ''));

        if ($pid === false || $expectedName === '') {
            return CommandOutcome::failure('Payload tidak valid: pid dan process_name wajib diisi.');
        }

        if (ProtectedProcesses::isProtected($expectedName) || $pid === getmypid()) {
            return CommandOutcome::failure("Proses '{$expectedName}' dilindungi dan tidak boleh diakhiri.");
        }

        // PID bisa dipakai ulang oleh OS; pastikan yang akan diakhiri memang proses yang dimaksud operator.
        $actualName = $this->findProcessName($pid);

        if ($actualName === null) {
            return CommandOutcome::failure("Proses dengan PID {$pid} sudah tidak berjalan.");
        }

        if (ProtectedProcesses::normalize($actualName) !== ProtectedProcesses::normalize($expectedName)
            || ProtectedProcesses::isProtected($actualName)) {
            return CommandOutcome::failure("PID {$pid} sekarang milik proses lain; perintah dibatalkan demi keamanan.");
        }

        $this->platform->terminateProcess($pid);
        // Daftar diperbarui agar dashboard langsung menampilkan hasilnya.
        $this->reporter->report();

        return CommandOutcome::success(['pid' => $pid, 'process_name' => $actualName]);
    }

    /** @param  array<string, mixed>  $payload */
    private function sendMessage(array $payload): CommandOutcome
    {
        $message = trim((string) ($payload['message'] ?? ''));

        if ($message === '') {
            return CommandOutcome::failure('Pesan kosong.');
        }

        $this->platform->showMessage(mb_substr($message, 0, self::MAX_MESSAGE_LENGTH));

        return CommandOutcome::success();
    }

    /**
     * @param  'restart'|'shutdown'  $action
     * @param  array<string, mixed>  $payload
     */
    private function power(string $action, array $payload): CommandOutcome
    {
        $delay = (int) ($payload['delay_seconds'] ?? 60);
        $delay = max(0, min($delay, self::MAX_POWER_DELAY_SECONDS));

        $this->platform->powerAction($action, $delay);

        return CommandOutcome::success(['action' => $action, 'delay_seconds' => $delay]);
    }

    private function findProcessName(int $pid): ?string
    {
        foreach ($this->platform->listProcesses() as $process) {
            if ($process->pid === $pid) {
                return $process->name;
            }
        }

        return null;
    }
}
