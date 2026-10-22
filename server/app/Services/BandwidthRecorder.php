<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\InterfaceSample;
use App\Support\BandwidthMath;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class BandwidthRecorder
{
    /**
     * Ubah counter kumulatif dari heartbeat menjadi sampel selisih per interface.
     *
     * @param  list<array{name: string, rx_bytes: int, tx_bytes: int, speed_bps?: int|null}>  $interfaces
     */
    public function record(Device $device, array $interfaces, CarbonInterface $now): void
    {
        if ($interfaces === []) {
            return;
        }

        DB::transaction(function () use ($device, $interfaces, $now): void {
            foreach ($interfaces as $reading) {
                $this->recordOne($device, $reading, $now);
            }
        });
    }

    /** @param  array{name: string, rx_bytes: int, tx_bytes: int, speed_bps?: int|null}  $reading */
    private function recordOne(Device $device, array $reading, CarbonInterface $now): void
    {
        $interface = DeviceInterface::query()
            ->where('device_id', $device->id)
            ->where('name', $reading['name'])
            ->lockForUpdate()
            ->first();

        if ($interface === null) {
            DeviceInterface::query()->create([
                'device_id' => $device->id,
                'name' => $reading['name'],
                'speed_bps' => $reading['speed_bps'] ?? null,
                'last_rx_bytes' => $reading['rx_bytes'],
                'last_tx_bytes' => $reading['tx_bytes'],
                'last_sample_at' => $now,
            ]);

            return;
        }

        $delta = BandwidthMath::delta(
            $interface->last_rx_bytes,
            $interface->last_tx_bytes,
            $interface->last_sample_at->getTimestamp(),
            $reading['rx_bytes'],
            $reading['tx_bytes'],
            $now->getTimestamp(),
        );

        if ($delta !== null) {
            InterfaceSample::query()->create([
                'device_interface_id' => $interface->id,
                'sampled_at' => $now,
                'rx_bytes' => $delta['rx'],
                'tx_bytes' => $delta['tx'],
                'interval_seconds' => $delta['seconds'],
            ]);
        }

        // Selalu rebase, sehingga setelah reboot/jeda panjang sampel berikutnya kembali normal.
        $interface->forceFill([
            'speed_bps' => $reading['speed_bps'] ?? $interface->speed_bps,
            'last_rx_bytes' => $reading['rx_bytes'],
            'last_tx_bytes' => $reading['tx_bytes'],
            'last_sample_at' => $now,
        ])->save();
    }
}
