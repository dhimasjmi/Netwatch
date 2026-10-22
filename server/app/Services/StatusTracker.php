<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConnectionStatus;
use App\Models\Device;
use App\Models\DeviceStatusHistory;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Support\Duration;
use Illuminate\Support\Facades\DB;

/**
 * Mencatat perubahan status online/offline sebagai interval berurutan.
 * Selalu ada paling banyak satu interval terbuka (ended_at NULL) per perangkat.
 */
final class StatusTracker
{
    public function __construct(private readonly EventRecorder $events)
    {
    }

    public function markOnline(Device $device, CarbonInterface $at, string $reason = 'heartbeat'): void
    {
        $this->transition($device, ConnectionStatus::Online, $at, $reason);
    }

    /**
     * Dipanggil saat heartbeat tiba setelah jeda panjang. Bila pengecekan terjadwal belum sempat menandai offline
     * (mis. scheduler tidak berjalan), gangguan dicatat mundur dari heartbeat terakhir sampai sekarang.
     */
    public function recordGapAndMarkOnline(Device $device, CarbonInterface $lastSeen, CarbonInterface $now, string $reason = 'heartbeat'): void
    {
        $handled = false;

        try {
            DB::transaction(function () use ($device, $lastSeen, $now, $reason, &$handled): void {
                $open = $this->lockOpenInterval($device);

                if ($open === null || $open->status !== ConnectionStatus::Online) {
                    return; // Sudah ditandai offline (atau belum ada riwayat): alur normal di bawah.
                }

                $gapStart = $this->notBefore($lastSeen, $open);
                $gapEnd = $now->lt($gapStart) ? $gapStart : $now;

                $open->forceFill(['ended_at' => $gapStart])->save();

                DeviceStatusHistory::query()->create([
                    'device_id' => $device->id,
                    'status' => ConnectionStatus::Offline,
                    'started_at' => $gapStart,
                    'ended_at' => $gapEnd,
                    'reason' => 'heartbeat_gap',
                ]);
                DeviceStatusHistory::query()->create([
                    'device_id' => $device->id,
                    'status' => ConnectionStatus::Online,
                    'started_at' => $gapEnd,
                    'reason' => $reason,
                ]);

                $this->events->record('device.offline', 'Perangkat berhenti mengirim heartbeat (terdeteksi saat heartbeat kembali).', $device, null,
                    ['reason' => 'heartbeat_gap'], $gapStart);
                $this->events->record('device.online', 'Perangkat kembali online setelah offline '.Duration::human($gapEnd->getTimestamp() - $gapStart->getTimestamp()).'.',
                    $device, null, ['downtime_seconds' => $gapEnd->getTimestamp() - $gapStart->getTimestamp()], $gapEnd);

                $handled = true;
            });
        } catch (UniqueConstraintViolationException) {
            return; // Proses lain sudah memperbarui status.
        }

        if (!$handled) {
            $this->markOnline($device, $now, $reason);
        }
    }

    public function markOffline(Device $device, CarbonInterface $at, string $reason = 'heartbeat_timeout'): void
    {
        $this->transition($device, ConnectionStatus::Offline, $at, $reason);
    }

    /** Tutup interval terbuka tanpa membuka yang baru (perangkat dicabut). */
    public function close(Device $device, CarbonInterface $at): void
    {
        DB::transaction(function () use ($device, $at): void {
            $open = $this->lockOpenInterval($device);

            $open?->forceFill(['ended_at' => $this->notBefore($at, $open)])->save();
        });
    }

    private function transition(Device $device, ConnectionStatus $status, CarbonInterface $at, string $reason): void
    {
        try {
            DB::transaction(function () use ($device, $status, $at, $reason): void {
                $open = $this->lockOpenInterval($device);

                if ($open?->status === $status) {
                    return;
                }

                $startedAt = $open === null ? $at : $this->notBefore($at, $open);
                $open?->forceFill(['ended_at' => $startedAt])->save();

                DeviceStatusHistory::query()->create([
                    'device_id' => $device->id,
                    'status' => $status,
                    'started_at' => $startedAt,
                    'reason' => $reason,
                ]);

                if ($open !== null) { // Perubahan nyata, bukan pembukaan riwayat pertama.
                    $this->recordTransitionEvent($device, $status, $startedAt, $open, $reason);
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Proses lain (heartbeat paralel atau pengecekan terjadwal) sudah membuka interval baru; tidak ada yang perlu dilakukan.
        }
    }

    private function recordTransitionEvent(Device $device, ConnectionStatus $status, CarbonInterface $at, DeviceStatusHistory $previous, string $reason): void
    {
        if ($status === ConnectionStatus::Offline) {
            $this->events->record('device.offline', 'Perangkat berhenti mengirim heartbeat.', $device, null, ['reason' => $reason], $at);

            return;
        }

        $downtime = max(0, $at->getTimestamp() - $previous->started_at->getTimestamp());
        $this->events->record('device.online', 'Perangkat kembali online setelah offline '.Duration::human($downtime).'.', $device, null,
            ['downtime_seconds' => $downtime, 'reason' => $reason], $at);
    }

    private function lockOpenInterval(Device $device): ?DeviceStatusHistory
    {
        return DeviceStatusHistory::query()
            ->where('device_id', $device->id)
            ->whereNull('ended_at')
            ->lockForUpdate()
            ->first();
    }

    /** Cegah waktu selesai lebih awal dari waktu mulai bila jam perangkat/server bergeser. */
    private function notBefore(CarbonInterface $at, DeviceStatusHistory $open): CarbonInterface
    {
        return $at->lt($open->started_at) ? $open->started_at : $at;
    }
}
