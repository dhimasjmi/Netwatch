<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\BlockRuleType;

/** Membaca daftar target dari kotak teks (satu per baris, atau dipisah koma/spasi) dan menebak jenisnya. Murni. */
final class PolicyEntries
{
    public const MAX_PER_SUBMIT = 200;

    /** @return list<string> entri unik sesuai urutan input, maksimal MAX_PER_SUBMIT */
    public static function parse(string $text): array
    {
        $parts = preg_split('/[\s,;]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $unique = [];

        foreach ($parts as $part) {
            $unique[mb_strtolower($part)] ??= $part;
        }

        return array_slice(array_values($unique), 0, self::MAX_PER_SUBMIT);
    }

    /** IP atau CIDR dikenali dari bentuknya; selain itu dianggap domain/situs. */
    public static function typeOf(string $entry): BlockRuleType
    {
        [$address] = explode('/', $entry, 2);

        return filter_var($address, FILTER_VALIDATE_IP) !== false ? BlockRuleType::Ip : BlockRuleType::Domain;
    }
}
