<?php

declare(strict_types=1);

namespace NetWatch\Agent\Http;

/** Kontrak pengiriman data ke server (memudahkan pengujian tanpa jaringan). */
interface ApiTransport
{
    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = []): array;
}
