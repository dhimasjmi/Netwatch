<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Daftar perintah yang boleh dikirim ke agent. Agent hanya menjalankan tipe di sini
 * (whitelist); tidak ada eksekusi shell bebas.
 */
enum CommandType: string
{
    case RefreshProcesses = 'refresh_processes';
    case TerminateProcess = 'terminate_process';
    case SendMessage = 'send_message';
    case RestartComputer = 'restart_computer';
    case ShutdownComputer = 'shutdown_computer';

    public function label(): string
    {
        return match ($this) {
            self::RefreshProcesses => 'Muat ulang daftar proses',
            self::TerminateProcess => 'Akhiri proses',
            self::SendMessage => 'Kirim pesan',
            self::RestartComputer => 'Restart komputer',
            self::ShutdownComputer => 'Matikan komputer',
        };
    }

    /** Perintah yang mengubah status daya perangkat hanya untuk administrator. */
    public function requiresPowerControl(): bool
    {
        return $this === self::RestartComputer || $this === self::ShutdownComputer;
    }
}
