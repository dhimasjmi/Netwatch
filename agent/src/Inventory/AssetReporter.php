<?php

declare(strict_types=1);

namespace NetWatch\Agent\Inventory;

use NetWatch\Agent\Http\ApiTransport;
use NetWatch\Agent\Platform\PlatformInterface;

/**
 * Mengirim inventaris perangkat keras/lunak hanya bila isinya berubah (atau sebagai penanda "masih terkini" tiap beberapa jam).
 * Pembacaan dilakukan sering (murah), pengiriman jarang, sehingga uninstall/instal tercermin di server dalam hitungan menit.
 */
final class AssetReporter
{
    private const KEEPALIVE_SECONDS = 21600;

    private ?string $lastHash = null;
    private float $lastSentAt = 0.0;

    public function __construct(private readonly PlatformInterface $platform, private readonly ApiTransport $api)
    {
    }

    /**
     * @return bool true bila data dikirim
     *
     * @throws \NetWatch\Agent\Platform\PlatformException
     */
    public function report(): bool
    {
        $assets = $this->platform->collectAssets();

        if ($assets['hardware'] === null && $assets['software'] === null) {
            return false; // tidak terbaca: jangan kirim, agar data lama di server tidak terhapus
        }

        $hash = hash('sha256', json_encode($assets, JSON_THROW_ON_ERROR));

        if ($hash === $this->lastHash && microtime(true) - $this->lastSentAt < self::KEEPALIVE_SECONDS) {
            return false;
        }

        $this->api->post('assets', $assets);
        $this->lastHash = $hash;
        $this->lastSentAt = microtime(true);

        return true;
    }
}
