<?php

declare(strict_types=1);

namespace NetWatch\Agent\Browsing;

use PDO;
use Throwable;

/** Membaca riwayat Chrome/Edge (SQLite) dari salinan berkas, karena browser mengunci berkas aslinya. */
final class HistoryReader
{
    public static function isAvailable(): bool
    {
        return extension_loaded('pdo_sqlite');
    }

    /**
     * Kunjungan setelah $afterChromeTime, urut waktu naik. Navigasi frame-dalam (iklan, iframe) dilewati.
     *
     * @return list<array{chrome_time: int, url: string}>
     *
     * @throws \RuntimeException bila berkas tidak bisa disalin atau dibaca
     */
    public function read(string $path, int $afterChromeTime, int $limit): array
    {
        $copy = tempnam(sys_get_temp_dir(), 'nwh');

        if ($copy === false || !@copy($path, $copy)) {
            throw new \RuntimeException('Berkas riwayat tidak bisa disalin (mungkin terkunci browser): ' . basename(dirname($path)));
        }

        try {
            $pdo = new PDO('sqlite:' . $copy, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $statement = $pdo->prepare(
                'SELECT v.visit_time AS t, u.url AS url FROM visits v JOIN urls u ON u.id = v.url '
                . 'WHERE v.visit_time > :after AND (v.transition & 255) NOT IN (3, 4) ORDER BY v.visit_time ASC LIMIT :limit'
            );
            $statement->bindValue(':after', $afterChromeTime, PDO::PARAM_INT);
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->execute();

            $rows = [];

            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[] = ['chrome_time' => (int) $row['t'], 'url' => (string) $row['url']];
            }

            return $rows;
        } catch (Throwable $e) {
            throw new \RuntimeException('Gagal membaca riwayat: ' . $e->getMessage());
        } finally {
            unset($pdo, $statement);
            @unlink($copy);
        }
    }
}
