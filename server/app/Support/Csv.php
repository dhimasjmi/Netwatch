<?php

declare(strict_types=1);

namespace App\Support;

final class Csv
{
    /** Netralkan sel yang akan dibaca Excel sebagai rumus (CSV injection) dari data yang dikendalikan pengguna. */
    public static function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
