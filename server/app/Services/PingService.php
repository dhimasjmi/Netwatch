<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Support\PingProbe;
use Symfony\Component\Process\Process;

/**
 * Ping ICMP dari server ke perangkat yang offline, untuk membedakan "PC mati/terputus" dari "hanya agent yang berhenti".
 * Memakai perintah ping bawaan OS tanpa shell (argumen terpisah), jadi nilai IP tidak bisa menyisipkan perintah.
 */
final class PingService
{
    private const CHUNK = 25;
    private const MAX_DEVICES = 500;

    /** @return int jumlah perangkat yang dicek */
    public function run(): int
    {
        $threshold = now()->subSeconds((int) config('netwatch.offline_after'));
        $windows = PHP_OS_FAMILY === 'Windows';
        $timeout = (float) config('netwatch.ping.timeout_seconds');
        $checked = 0;

        $devices = Device::query()->active()
            ->whereNotNull('ip_address')->whereNotNull('last_seen_at')->where('last_seen_at', '<=', $threshold)
            ->orderBy('id')->limit(self::MAX_DEVICES)->get();

        foreach ($devices->chunk(self::CHUNK) as $chunk) {
            $running = [];
            foreach ($chunk as $device) {
                $command = PingProbe::command($device->ip_address, $windows);
                if ($command === null) {
                    continue;
                }
                $process = new Process($command, null, null, null, $timeout);
                $process->start();
                $running[] = [$device, $process];
            }

            foreach ($running as [$device, $process]) {
                $this->finish($device, $process);
                $checked++;
            }
        }

        return $checked;
    }

    private function finish(Device $device, Process $process): void
    {
        try {
            $process->wait();
            $output = $process->getOutput();
            $ok = PingProbe::succeeded((int) $process->getExitCode(), $output);
        } catch (\Throwable) { // timeout atau ping tidak terpasang: anggap tidak merespons
            $output = '';
            $ok = false;
        }

        $device->forceFill([
            'ping_ok' => $ok,
            'ping_ms' => $ok ? PingProbe::milliseconds($output) : null,
            'ping_checked_at' => now(),
        ])->save();
    }
}
