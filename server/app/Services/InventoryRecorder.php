<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\DevicePort;
use App\Models\DeviceService;
use App\Models\Event;
use App\Support\InventoryEntry;
use App\Support\PortCatalog;
use Illuminate\Support\Facades\DB;

/** Menyimpan snapshot port dan layanan dari agent sebagai kondisi terkini, dan mencatat event untuk port baru. */
final class InventoryRecorder
{
    private const MAX_NEW_PORT_EVENTS = 10;
    private const EVENT_COOLDOWN_HOURS = 24;

    public function __construct(private readonly EventRecorder $events)
    {
    }

    /**
     * @param  list<array<string, mixed>>  $ports
     * @param  list<array<string, mixed>>  $services
     * @return array{ports: int, services: int}
     */
    public function sync(Device $device, array $ports, array $services): array
    {
        $now = now();
        $baseline = $device->inventory_reported_at !== null;

        $portRows = [];
        foreach ($ports as $raw) {
            $row = is_array($raw) ? InventoryEntry::port($raw) : null;
            if ($row !== null) {
                $portRows[$row['protocol'].'|'.$row['address'].'|'.$row['port']] = $row;
            }
        }

        $serviceRows = [];
        foreach ($services as $raw) {
            $row = is_array($raw) ? InventoryEntry::service($raw) : null;
            if ($row !== null) {
                $serviceRows[$row['name']] = $row;
            }
        }

        $newPorts = DB::transaction(function () use ($device, $portRows, $serviceRows, $now): array {
            $known = DevicePort::query()->where('device_id', $device->id)
                ->get(['protocol', 'address', 'port'])
                ->mapWithKeys(fn (DevicePort $p): array => [$p->protocol.'|'.$p->address.'|'.$p->port => true])->all();

            $new = array_diff_key($portRows, $known);

            foreach (array_chunk(array_values($portRows), 500) as $chunk) {
                DevicePort::query()->upsert(
                    array_map(fn (array $r): array => $r + ['device_id' => $device->id, 'first_seen_at' => $now, 'last_seen_at' => $now], $chunk),
                    ['device_id', 'protocol', 'address', 'port'],
                    ['process', 'exposed', 'last_seen_at'], // first_seen_at sengaja tidak ikut diperbarui
                );
            }
            DevicePort::query()->where('device_id', $device->id)->where('last_seen_at', '<', $now)->delete();

            foreach (array_chunk(array_values($serviceRows), 500) as $chunk) {
                DeviceService::query()->upsert(
                    array_map(fn (array $r): array => $r + ['device_id' => $device->id, 'updated_at' => $now], $chunk),
                    ['device_id', 'name'],
                    ['display_name', 'state', 'start_mode', 'updated_at'],
                );
            }
            DeviceService::query()->where('device_id', $device->id)->where('updated_at', '<', $now)->delete();

            $device->forceFill(['inventory_reported_at' => $now])->save();

            return $new;
        });

        if ($baseline) {
            $this->recordNewPorts($device, $newPorts);
        }

        return ['ports' => count($portRows), 'services' => count($serviceRows)];
    }

    /** @param  array<string, array<string, mixed>>  $newPorts */
    private function recordNewPorts(Device $device, array $newPorts): void
    {
        $candidates = array_filter($newPorts, static fn (array $p): bool => $p['exposed'] && $p['protocol'] === 'tcp' && $p['port'] < InventoryEntry::EPHEMERAL_START);

        if ($candidates === []) {
            return;
        }

        // Port yang sama tidak diumumkan lagi dalam 24 jam, supaya aplikasi yang sering restart tidak membanjiri event.
        $recent = Event::query()->where('device_id', $device->id)->where('code', 'device.port_opened')
            ->where('occurred_at', '>=', now()->subHours(self::EVENT_COOLDOWN_HOURS))->get(['details'])
            ->map(fn (Event $e): int => (int) ($e->details['port'] ?? 0))->flip()->all();

        $count = 0;
        foreach ($candidates as $port) {
            if (isset($recent[$port['port']]) || $count >= self::MAX_NEW_PORT_EVENTS) {
                continue;
            }
            $count++;
            $name = PortCatalog::name($port['port']);
            $this->events->record(
                'device.port_opened',
                'Port TCP '.$port['port'].($name !== null ? " ({$name})" : '').' baru terbuka'.($port['process'] !== null ? ' oleh proses '.$port['process'] : '').'.',
                $device, null, ['port' => $port['port'], 'protocol' => 'tcp', 'process' => $port['process'], 'address' => $port['address'], 'hint' => PortCatalog::hint($port['port'])],
            );
        }
    }
}
