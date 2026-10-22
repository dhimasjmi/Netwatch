<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Menyusun teks notifikasi dari daftar kejadian. Murni (tanpa database/jaringan) agar mudah diuji.
 *
 * Setiap item: ['kind' => 'offline'|'online'|'other', 'name' => string, 'at' => string,
 *               'ping_ok' => ?bool, 'ping_ms' => ?float, 'text' => string (untuk 'other'), 'downtime' => ?string]
 */
final class AlertMessage
{
    private const MAX_LISTED = 15;

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public static function build(array $items, int $groupThreshold = 5): string
    {
        $offline = array_values(array_filter($items, static fn (array $i): bool => $i['kind'] === 'offline'));
        $online = array_values(array_filter($items, static fn (array $i): bool => $i['kind'] === 'online'));
        $other = array_values(array_filter($items, static fn (array $i): bool => $i['kind'] === 'other'));

        $lines = [];

        if (count($offline) >= max(2, $groupThreshold)) {
            $names = array_map(static fn (array $i): string => (string) $i['name'], $offline);
            $lines[] = '🔴 NetWatch: '.count($offline).' perangkat offline bersamaan.';
            $lines[] = self::listNames($names);
            $lines[] = 'Bila banyak yang offline sekaligus, curigai switch/AP, kabel uplink, atau server NetWatch, bukan PC masing-masing.';
        } else {
            foreach ($offline as $item) {
                $lines[] = '🔴 '.$item['name'].' OFFLINE sejak '.$item['at'].'. '.self::pingText($item);
            }
        }

        foreach ($online as $item) {
            $suffix = isset($item['downtime']) && $item['downtime'] !== null ? ' (offline '.$item['downtime'].')' : '';
            $lines[] = '🟢 '.$item['name'].' kembali ONLINE pukul '.$item['at'].$suffix.'.';
        }

        foreach ($other as $item) {
            $lines[] = 'ℹ️ '.$item['text'];
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $item */
    public static function pingText(array $item): string
    {
        return match ($item['ping_ok'] ?? null) {
            true => 'Ping dari server: merespons'.(isset($item['ping_ms']) ? ' ('.round((float) $item['ping_ms'], 1).' ms)' : '')
                .' → PC menyala dan jaringan normal, kemungkinan layanan agent berhenti.',
            false => 'Ping dari server: tidak merespons → PC mati/sleep atau terputus dari jaringan.',
            default => 'Ping dari server: belum diperiksa.',
        };
    }

    /** @param list<string> $names */
    private static function listNames(array $names): string
    {
        $shown = array_slice($names, 0, self::MAX_LISTED);
        $rest = count($names) - count($shown);

        return implode(', ', $shown).($rest > 0 ? " dan {$rest} lainnya" : '');
    }
}
