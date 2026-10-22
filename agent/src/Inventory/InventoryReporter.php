<?php

declare(strict_types=1);

namespace NetWatch\Agent\Inventory;

use NetWatch\Agent\Http\ApiTransport;
use NetWatch\Agent\Platform\PlatformInterface;

/** Mengirim snapshot port terbuka dan layanan sistem ke server. */
final class InventoryReporter
{
    private const MAX_PORTS = 3000;
    private const MAX_SERVICES = 1500;

    public function __construct(private readonly PlatformInterface $platform, private readonly ApiTransport $api)
    {
    }

    /**
     * @return array{ports: int, services: int} jumlah yang dikirim; 0/0 bila pembacaan kosong (tidak dikirim, agar data lama di server tidak terhapus)
     *
     * @throws \NetWatch\Agent\Platform\PlatformException
     */
    public function report(): array
    {
        $inventory = $this->platform->collectInventory();

        $ports = [];
        foreach ($inventory['ports'] as $port) {
            // Duplikat (satu port bisa muncul beberapa kali dengan alamat sama) dibuang.
            $ports[$port['protocol'] . '|' . $port['address'] . '|' . $port['port']] = $port;
        }
        $ports = array_slice(array_values($ports), 0, self::MAX_PORTS);

        $services = [];
        foreach ($inventory['services'] as $service) {
            $services[$service['name']] = $service;
        }
        $services = array_slice(array_values($services), 0, self::MAX_SERVICES);

        if ($ports === [] && $services === []) {
            return ['ports' => 0, 'services' => 0];
        }

        $this->api->post('inventory', ['ports' => $ports, 'services' => $services]);

        return ['ports' => count($ports), 'services' => count($services)];
    }
}
