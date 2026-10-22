<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BlockRuleType;
use App\Services\Exceptions\BlockRuleException;
use App\Support\BlockTarget;
use InvalidArgumentException;
use Throwable;

/**
 * Menormalkan target blokir dan menolak yang berbahaya: server NetWatch tidak boleh ikut terblokir,
 * sebab agent akan kehilangan koneksi ke server dan tidak bisa menerima pencabutan aturan.
 */
final class BlockRuleGuard
{
    /**
     * @return string nilai kanonik yang aman disimpan dan dikirim ke agent
     *
     * @throws BlockRuleException
     */
    public function normalize(BlockRuleType $type, string $input): string
    {
        try {
            $value = $type === BlockRuleType::Domain ? BlockTarget::normalizeDomain($input) : BlockTarget::normalizeIp($input);
        } catch (InvalidArgumentException $e) {
            throw new BlockRuleException($e->getMessage());
        }

        $this->assertNotServer($type, $value);

        return $value;
    }

    /** @throws BlockRuleException */
    private function assertNotServer(BlockRuleType $type, string $value): void
    {
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($type === BlockRuleType::Domain) {
            if ($host !== '' && BlockTarget::domainCoversHost($value, $host)) {
                throw new BlockRuleException("Domain ini memuat alamat server NetWatch ({$host}); memblokirnya akan memutus semua agent.");
            }

            return;
        }

        foreach ($this->serverIps($host) as $serverIp) {
            if (BlockTarget::covers($value, $serverIp)) {
                throw new BlockRuleException("Rentang ini memuat alamat server NetWatch ({$serverIp}); memblokirnya akan memutus semua agent.");
            }
        }
    }

    /**
     * Bila NETWATCH_SERVER_IPS diisi, daftar itu dipakai apa adanya; bila kosong, host dari APP_URL di-resolve lewat DNS.
     *
     * @return list<string>
     */
    private function serverIps(string $host): array
    {
        $ips = (array) config('netwatch.server_ips', []);

        if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips[] = $host;
        } elseif ($ips === [] && $host !== '') {
            try {
                $resolved = gethostbynamel($host);
                $ips = $resolved === false ? [] : $resolved;
            } catch (Throwable) {
                // DNS gagal: pemeriksaan IP dilewati; pemeriksaan domain tetap berjalan.
            }
        }

        return array_values(array_unique(array_filter($ips, fn ($ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false)));
    }
}
