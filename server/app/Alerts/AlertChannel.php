<?php

declare(strict_types=1);

namespace App\Alerts;

interface AlertChannel
{
    public function name(): string;

    public function isConfigured(): bool;

    /** @return string|null null bila berhasil, atau pesan galat singkat (tanpa rahasia seperti token) */
    public function send(string $subject, string $text): ?string;
}
