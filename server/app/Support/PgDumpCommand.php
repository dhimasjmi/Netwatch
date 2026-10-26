<?php

declare(strict_types=1);

namespace App\Support;

/** Menyusun argumen pg_dump/pg_restore sebagai daftar (tanpa shell). Kata sandi lewat environment, bukan argumen. */
final class PgDumpCommand
{
    /**
     * @param  array<string, mixed>  $db  isi config('database.connections.pgsql')
     * @param  list<string>  $excludeData  tabel yang datanya dilewati (struktur tetap ikut)
     * @return list<string>
     */
    public static function dump(string $binary, array $db, string $outFile, array $excludeData): array
    {
        $args = [$binary, '--format=custom', '--compress=6', '--no-owner', '--no-privileges', '--lock-wait-timeout=60s', ...self::connection($db)];
        foreach ($excludeData as $table) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) === 1) { // nama tabel saja, bukan pola bebas
                $args[] = '--exclude-table-data=public.'.$table;
            }
        }
        $args[] = '--file='.$outFile;

        return $args;
    }

    /** @return list<string> */
    public static function listContents(string $binary, string $file): array
    {
        return [$binary, '--list', $file];
    }

    /** @param  array<string, mixed>  $db
     * @return array<string, string> */
    public static function environment(array $db): array
    {
        $password = (string) ($db['password'] ?? '');

        return $password === '' ? [] : ['PGPASSWORD' => $password];
    }

    /** @param  array<string, mixed>  $db
     * @return list<string> */
    private static function connection(array $db): array
    {
        $args = [];
        foreach (['host' => '--host=', 'port' => '--port=', 'username' => '--username='] as $key => $flag) {
            if (($db[$key] ?? '') !== '') {
                $args[] = $flag.$db[$key];
            }
        }
        $args[] = '--dbname='.(string) ($db['database'] ?? '');

        return $args;
    }
}
