<?php

declare(strict_types=1);

namespace App\Support;

/** Memilih backup yang boleh dihapus: simpan N harian, N mingguan, dan N bulanan terbaru (murni, mudah diuji). */
final class BackupRetention
{
    /**
     * @param  array<string, int>  $files  nama berkas => waktu pembuatan (unix, UTC)
     * @return list<string> nama berkas yang boleh dihapus
     */
    public static function obsolete(array $files, int $keepDaily, int $keepWeekly, int $keepMonthly): array
    {
        arsort($files); // terbaru dulu
        $keep = [];

        foreach ([['Y-m-d', $keepDaily], ['o-\WW', $keepWeekly], ['Y-m', $keepMonthly]] as [$format, $limit]) {
            $seen = [];
            foreach ($files as $name => $time) {
                $slot = gmdate($format, $time);
                if (isset($seen[$slot])) {
                    continue;
                }
                if (count($seen) >= $limit) {
                    break;
                }
                $seen[$slot] = true;
                $keep[$name] = true; // backup terbaru pada hari/minggu/bulan itu
            }
        }

        // Backup terbaru selalu dipertahankan, apa pun pengaturannya.
        if ($files !== []) {
            $keep[array_key_first($files)] = true;
        }

        return array_values(array_diff(array_keys($files), array_keys($keep)));
    }

    /** Waktu (unix UTC) dari nama berkas netwatch-YYYYmmdd-HHMMSS.dump, atau null bila bukan berkas backup kita. */
    public static function timeFromName(string $name): ?int
    {
        if (preg_match('/^netwatch-(\d{8})-(\d{6})\.dump$/', $name, $m) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Ymd-His', $m[1].'-'.$m[2], new \DateTimeZone('UTC'));

        // createFromFormat menggulirkan tanggal mustahil (bulan 13, jam 25) alih-alih gagal; anggap itu bukan berkas kita.
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date->getTimestamp();
    }

    public static function nameFor(int $time): string
    {
        return 'netwatch-'.gmdate('Ymd-His', $time).'.dump';
    }
}
