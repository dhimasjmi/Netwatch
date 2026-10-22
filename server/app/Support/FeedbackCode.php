<?php

declare(strict_types=1);

namespace App\Support;

/** Kode penilaian sekali pakai: pendek agar mudah diketik dari popup di PC, dengan huruf yang tidak membingungkan. */
final class FeedbackCode
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const LENGTH = 10;

    public static function generate(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    /** Merapikan masukan pengguna (huruf kecil, spasi, tanda hubung) sebelum dicari; null bila bentuknya tidak mungkin valid. */
    public static function normalize(string $input): ?string
    {
        $code = strtoupper((string) preg_replace('/[\s-]+/', '', $input));

        return preg_match('/^['.self::ALPHABET.']{'.self::LENGTH.'}$/', $code) === 1 ? $code : null;
    }

    public static function hash(string $code): string
    {
        return hash('sha256', $code);
    }
}
