<?php

declare(strict_types=1);

namespace App\Support;

/** Mencocokkan nama host dengan daftar domain: cocok bila sama persis atau subdomain dari salah satu domain. Murni agar mudah diuji. */
final class HostMatcher
{
    /** @param  array<string, true>  $domains domain huruf kecil sebagai kunci */
    public static function matches(?string $host, array $domains): bool
    {
        if ($host === null || $host === '' || $domains === []) {
            return false;
        }

        $labels = explode('.', strtolower(rtrim($host, '.')));

        // Telusuri host itu sendiri, lalu induknya: a.b.c -> a.b.c, b.c, c.
        for ($i = 0, $n = count($labels); $i < $n; $i++) {
            if (isset($domains[implode('.', array_slice($labels, $i))])) {
                return true;
            }
        }

        return false;
    }

    /** Merapikan masukan admin ("*.Contoh.com." -> "contoh.com"); null bila bukan nama domain yang valid. */
    public static function normalizeDomain(string $input): ?string
    {
        $domain = strtolower(trim($input));
        $domain = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain, 2)[0];
        $domain = rtrim(ltrim($domain, '*.'), '.');

        return preg_match('/^(?=.{3,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) === 1 ? $domain : null;
    }
}
