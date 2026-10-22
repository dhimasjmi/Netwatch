<?php

declare(strict_types=1);

namespace NetWatch\Agent\Inventory;

/** Membersihkan data inventaris hasil pembacaan OS: memotong panjang, membuang baris tidak valid, dan menghapus duplikat. */
final class AssetNormalizer
{
    public const MAX_SOFTWARE = 3000;
    public const MAX_DISKS = 16;

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null null bila tidak ada satu pun informasi berguna
     */
    public static function hardware(array $raw): ?array
    {
        $disks = [];
        foreach (is_array($raw['disks'] ?? null) ? $raw['disks'] : [] as $disk) {
            $model = is_array($disk) ? self::text($disk['model'] ?? null, 100) : null;
            $size = is_array($disk) && isset($disk['size_gb']) && is_numeric($disk['size_gb']) ? (int) $disk['size_gb'] : 0;
            if ($model !== null && $size > 0) {
                $disks[] = ['model' => $model, 'size_gb' => $size];
            }
        }

        $hardware = [
            'manufacturer' => self::text($raw['manufacturer'] ?? null, 100),
            'model' => self::text($raw['model'] ?? null, 100),
            'serial' => self::serial($raw['serial'] ?? null),
            'cpu' => self::text($raw['cpu'] ?? null, 150),
            'cpu_cores' => self::count($raw['cpu_cores'] ?? null),
            'cpu_threads' => self::count($raw['cpu_threads'] ?? null),
            'memory_mb' => self::count($raw['memory_mb'] ?? null),
            'os' => self::text($raw['os'] ?? null, 100),
            'os_version' => self::text($raw['os_version'] ?? null, 50),
            'os_build' => self::text($raw['os_build'] ?? null, 50),
            'disks' => array_slice($disks, 0, self::MAX_DISKS),
        ];

        $hasInfo = array_filter($hardware, static fn (mixed $v): bool => $v !== null && $v !== []) !== [];

        return $hasInfo ? $hardware : null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{name: string, version: string|null, publisher: string|null, installed: string|null}>|null null bila tidak ada data (dianggap tidak terbaca)
     */
    public static function software(array $rows): ?array
    {
        $unique = [];
        foreach ($rows as $row) {
            $name = self::text($row['name'] ?? null, 300);
            if ($name === null) {
                continue;
            }
            $version = self::text($row['version'] ?? null, 100);
            $installed = is_string($row['installed'] ?? null) && preg_match('/^(\d{4})(\d{2})(\d{2})$/', $row['installed'], $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;

            $unique[$name . '|' . ($version ?? '')] ??= ['name' => $name, 'version' => $version, 'publisher' => self::text($row['publisher'] ?? null, 200), 'installed' => $installed];
        }

        if ($unique === []) {
            return null;
        }

        ksort($unique);

        return array_slice(array_values($unique), 0, self::MAX_SOFTWARE);
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value));

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }

    /** Nomor seri bawaan pabrik yang tidak diisi ("To be filled by O.E.M.", "Default string") dianggap kosong. */
    private static function serial(mixed $value): ?string
    {
        $serial = self::text($value, 100);

        return $serial === null || preg_match('/^(to be filled|default string|none|n\/a|0+|system serial)/i', $serial) === 1 ? null : $serial;
    }

    private static function count(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
