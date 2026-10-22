<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Token acak berentropi tinggi; hanya hash SHA-256 yang disimpan di database,
 * sehingga kebocoran database tidak membocorkan token yang masih berlaku.
 */
final class TokenService
{
    public function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
