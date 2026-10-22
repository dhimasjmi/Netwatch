<?php

declare(strict_types=1);

namespace NetWatch\Agent\Policy;

use RuntimeException;

/**
 * Mengelola satu blok bertanda di file hosts. Isi di luar blok tidak pernah disentuh.
 * Hosts tidak mengenal wildcard: domain diblokir untuk nama itu dan "www."-nya; subdomain lain
 * ditangani kebijakan browser (URLBlocklist).
 */
final class HostsFile
{
    private const BEGIN = '# BEGIN NETWATCH';
    private const END = '# END NETWATCH';
    private const DOMAIN_MARK = '# domain: ';
    private const EOL = "\r\n";

    /** @param  list<string>  $domains */
    public static function render(string $current, array $domains): string
    {
        $stripped = self::strip($current);

        if ($domains === []) {
            return $stripped;
        }

        $lines = [self::BEGIN . ' (dikelola otomatis oleh NetWatch Agent, jangan diubah)'];

        foreach ($domains as $domain) {
            $lines[] = self::DOMAIN_MARK . $domain; // daftar asli, agar kondisi terpasang bisa dibaca kembali tanpa menebak
        }

        foreach ($domains as $domain) {
            foreach (self::namesFor($domain) as $name) {
                // IPv4 dan IPv6 sekaligus; tanpa baris IPv6 browser bisa tetap mencapai situs lewat AAAA.
                $lines[] = "0.0.0.0 {$name}";
                $lines[] = ":: {$name}";
            }
        }

        $lines[] = self::END;
        $base = rtrim($stripped, "\r\n");

        return ($base === '' ? '' : $base . self::EOL . self::EOL) . implode(self::EOL, $lines) . self::EOL;
    }

    /**
     * Domain yang tercatat dalam blok NetWatch (terurut). Blok tanpa penanda domain (format lama) dianggap kosong,
     * sehingga terdeteksi sebagai menyimpang dan ditulis ulang.
     *
     * @return list<string>
     */
    public static function domainsIn(string $content): array
    {
        if (preg_match('/^# BEGIN NETWATCH[^\r\n]*\r?\n(.*?)^# END NETWATCH/ms', $content, $block) !== 1) {
            return [];
        }

        preg_match_all('/^# domain: (\S+)[ \t]*\r?$/m', $block[1], $matches);
        $domains = array_values(array_unique($matches[1]));
        sort($domains, SORT_STRING);

        return $domains;
    }

    /** Buang blok NetWatch (termasuk blok yang rusak karena penanda akhirnya hilang). */
    public static function strip(string $content): string
    {
        $result = preg_replace('/(?:\r?\n)*^# BEGIN NETWATCH[^\r\n]*\r?\n.*?^# END NETWATCH[ \t]*(?:\r?\n|\z)/ms', '', $content);
        $result = preg_replace('/(?:\r?\n)*^# BEGIN NETWATCH.*\z/ms', '', (string) $result);
        $result = (string) $result;

        return trim($result) === '' ? '' : rtrim($result, "\r\n") . self::EOL;
    }

    /**
     * Terapkan ke berkas. Mengembalikan true bila isi berkas berubah.
     *
     * @param  list<string>  $domains
     *
     * @throws RuntimeException
     */
    public static function apply(string $path, array $domains): bool
    {
        $current = is_file($path) ? file_get_contents($path) : '';

        if ($current === false) {
            throw new RuntimeException("Tidak dapat membaca {$path}");
        }

        $updated = self::render($current, $domains);

        if ($updated === $current) {
            return false;
        }

        if (file_put_contents($path, $updated, LOCK_EX) === false) {
            throw new RuntimeException("Tidak dapat menulis {$path} (dikunci antivirus atau tanpa hak akses?)");
        }

        return true;
    }

    /** @return list<string> */
    private static function namesFor(string $domain): array
    {
        return str_starts_with($domain, 'www.') ? [$domain] : [$domain, 'www.' . $domain];
    }
}
