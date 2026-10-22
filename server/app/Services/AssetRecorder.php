<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceHardware;
use App\Models\DeviceSoftware;
use Illuminate\Support\Facades\DB;

/**
 * Menyimpan inventaris perangkat keras dan lunak sebagai kondisi terkini. Snapshot perangkat lunak bersifat otoritatif:
 * program yang tidak ada lagi di laporan dihapus (uninstall tercermin), dan perubahan dicatat sebagai event setelah baseline ada.
 */
final class AssetRecorder
{
    private const MAX_EVENTS_PER_KIND = 10;

    public function __construct(private readonly EventRecorder $events)
    {
    }

    /**
     * @param  array<string, mixed>|null  $hardware  null = tidak dilaporkan, data lama dipertahankan
     * @param  list<array<string, mixed>>|null  $software  null = tidak dilaporkan, data lama dipertahankan
     * @return array{software: int, hardware_changed: bool}
     */
    public function sync(Device $device, ?array $hardware, ?array $software): array
    {
        $now = now();
        $baseline = $device->assets_reported_at !== null;
        $added = $removed = [];
        $changed = [];

        DB::transaction(function () use ($device, $hardware, $software, $now, &$added, &$removed, &$changed): void {
            if ($hardware !== null) {
                $changed = $this->syncHardware($device, $hardware, $now);
            }

            if ($software !== null) {
                [$added, $removed] = $this->syncSoftware($device, $software, $now);
            }

            $device->forceFill(['assets_reported_at' => $now])->save();
        });

        if ($baseline) {
            $this->record($device, $added, $removed, $changed);
        }

        return ['software' => count($software ?? []), 'hardware_changed' => $changed !== []];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string> nama bagian yang berubah (kosong pada laporan pertama)
     */
    private function syncHardware(Device $device, array $data, \Illuminate\Support\Carbon $now): array
    {
        $values = array_intersect_key($data, array_flip(['manufacturer', 'model', 'serial', 'cpu', 'cpu_cores', 'cpu_threads', 'memory_mb', 'os', 'os_version', 'os_build', 'disks']));
        $values += array_fill_keys(['manufacturer', 'model', 'serial', 'cpu', 'cpu_cores', 'cpu_threads', 'memory_mb', 'os', 'os_version', 'os_build', 'disks'], null);

        $existing = DeviceHardware::query()->where('device_id', $device->id)->first();
        $changed = [];

        if ($existing !== null) {
            foreach (['cpu' => 'CPU', 'memory_mb' => 'RAM', 'model' => 'model'] as $field => $label) {
                if ($existing->{$field} !== null && $existing->{$field} !== $values[$field]) {
                    $changed[] = $label;
                }
            }
            if ($existing->disks !== null && $existing->disks != ($values['disks'] ?? [])) {
                $changed[] = 'disk';
            }
        }

        DeviceHardware::query()->updateOrCreate(['device_id' => $device->id], $values + ['updated_at' => $now]);

        return $changed;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<string>, 1: list<string>} nama program baru dan yang hilang
     */
    private function syncSoftware(Device $device, array $rows, \Illuminate\Support\Carbon $now): array
    {
        $incoming = [];
        foreach ($rows as $row) {
            $incoming[$row['name']."\0".($row['version'] ?? '')] = [
                'device_id' => $device->id,
                'name' => $row['name'],
                'version' => $row['version'] ?? '',
                'publisher' => $row['publisher'] ?? null,
                'installed_on' => $row['installed'] ?? null,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ];
        }

        $knownNames = DeviceSoftware::query()->where('device_id', $device->id)->pluck('name')->flip()->all();

        foreach (array_chunk(array_values($incoming), 500) as $chunk) {
            DeviceSoftware::query()->upsert($chunk, ['device_id', 'name', 'version'], ['publisher', 'installed_on', 'last_seen_at']); // first_seen_at dipertahankan
        }

        $gone = DeviceSoftware::query()->where('device_id', $device->id)->where('last_seen_at', '<', $now);
        $goneNames = (clone $gone)->pluck('name')->unique()->all();
        $gone->delete();

        $incomingNames = array_unique(array_column($incoming, 'name'));

        // Perubahan versi (nama sama) dianggap pembaruan, bukan instal/uninstall.
        return [
            array_values(array_diff($incomingNames, array_keys($knownNames))),
            array_values(array_diff($goneNames, $incomingNames)),
        ];
    }

    /**
     * @param  list<string>  $added
     * @param  list<string>  $removed
     * @param  list<string>  $changed
     */
    private function record(Device $device, array $added, array $removed, array $changed): void
    {
        foreach (array_slice($added, 0, self::MAX_EVENTS_PER_KIND) as $name) {
            $this->events->record('software.installed', "Program terpasang: {$name}.", $device, null, ['name' => $name]);
        }
        foreach (array_slice($removed, 0, self::MAX_EVENTS_PER_KIND) as $name) {
            $this->events->record('software.removed', "Program dihapus: {$name}.", $device, null, ['name' => $name]);
        }
        if ($changed !== []) {
            $this->events->record('hardware.changed', 'Perangkat keras berubah: '.implode(', ', $changed).'.', $device, null, ['parts' => $changed]);
        }
    }
}
