<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Support\BandwidthMath;
use App\Support\BandwidthSamples;
use App\Support\DateRange;

final class BandwidthReportService
{
    /**
     * Data grafik tren per interface untuk satu rentang waktu.
     *
     * @return array{bucket_seconds: int, from: int, to: int, interfaces: list<array<string, mixed>>}
     */
    public function forDevice(Device $device, DateRange $range): array
    {
        $bucket = BandwidthMath::bucketSeconds($range->seconds());
        $interfaces = DeviceInterface::query()->where('device_id', $device->id)->orderBy('name')->get();

        // $bucket adalah integer dari daftar tetap, aman disisipkan langsung (menghindari masalah tipe parameter di PostgreSQL).
        $rows = BandwidthSamples::between($range->from, $range->to)
            ->whereIn('device_interface_id', $interfaces->modelKeys())
            ->selectRaw("device_interface_id, floor(extract(epoch from sampled_at) / {$bucket})::bigint * {$bucket} AS bucket_ts, sum(rx_bytes)::bigint AS rx, sum(tx_bytes)::bigint AS tx, sum(interval_seconds)::bigint AS covered")
            ->groupBy('device_interface_id', 'bucket_ts')
            ->orderBy('bucket_ts')
            ->get()
            ->groupBy('device_interface_id');

        $result = [];

        foreach ($interfaces as $interface) {
            $result[] = $this->summarize($interface, $rows->get($interface->id, collect()), $bucket);
        }

        return [
            'bucket_seconds' => $bucket,
            'from' => $range->from->getTimestamp(),
            'to' => $range->to->getTimestamp(),
            'interfaces' => $result,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array<string, mixed>
     */
    private function summarize(DeviceInterface $interface, $rows, int $bucket): array
    {
        $points = [];
        $totalRx = $totalTx = $peakRx = $peakTx = $covered = 0;

        foreach ($rows as $row) {
            // Pembagi memakai waktu yang benar-benar tercatat, bukan lebar bucket, agar bucket sebagian tidak tampak rendah.
            $seconds = max(1, (int) $row->covered);
            $rx = BandwidthMath::bitsPerSecond((int) $row->rx, $seconds);
            $tx = BandwidthMath::bitsPerSecond((int) $row->tx, $seconds);

            $points[] = [(int) $row->bucket_ts, round($rx), round($tx)];
            $totalRx += (int) $row->rx;
            $totalTx += (int) $row->tx;
            $covered += $seconds;
            $peakRx = max($peakRx, $rx);
            $peakTx = max($peakTx, $tx);
        }

        return [
            'id' => $interface->id,
            'name' => $interface->name,
            'speed_bps' => $interface->speed_bps,
            'total_rx_bytes' => $totalRx,
            'total_tx_bytes' => $totalTx,
            'avg_rx_bps' => round(BandwidthMath::bitsPerSecond($totalRx, $covered)),
            'avg_tx_bps' => round(BandwidthMath::bitsPerSecond($totalTx, $covered)),
            'peak_rx_bps' => round($peakRx),
            'peak_tx_bps' => round($peakTx),
            'points' => $points,
        ];
    }
}
