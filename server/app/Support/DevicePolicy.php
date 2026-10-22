<?php

declare(strict_types=1);

namespace App\Support;

/** Kebijakan blokir efektif untuk satu perangkat (hasil gabungan semua aturan yang berlaku). */
final class DevicePolicy
{
    /**
     * @param  list<string>  $domains
     * @param  list<string>  $ips
     */
    public function __construct(public readonly array $domains, public readonly array $ips)
    {
    }

    /** @param  iterable<string>  $domains @param  iterable<string>  $ips */
    public static function fromValues(iterable $domains, iterable $ips): self
    {
        return new self(self::sortedUnique($domains), self::sortedUnique($ips));
    }

    /** Hash kanonik: dua kebijakan dengan isi sama selalu berhash sama, apa pun urutan aturannya. */
    public function hash(): string
    {
        return hash('sha256', (string) json_encode(['d' => $this->domains, 'i' => $this->ips], JSON_UNESCAPED_SLASHES));
    }

    public function isEmpty(): bool
    {
        return $this->domains === [] && $this->ips === [];
    }

    /** @return array{hash: string, domains: list<string>, ips: list<string>} */
    public function toPayload(): array
    {
        return ['hash' => $this->hash(), 'domains' => $this->domains, 'ips' => $this->ips];
    }

    /**
     * @param  iterable<string>  $values
     * @return list<string>
     */
    private static function sortedUnique(iterable $values): array
    {
        $unique = array_values(array_unique(is_array($values) ? $values : iterator_to_array($values, false)));
        sort($unique, SORT_STRING);

        return $unique;
    }
}
