<?php

declare(strict_types=1);

namespace App\Services\Probe;

final class ProbeResult
{
    public function __construct(public readonly bool $ok, public readonly ?float $latencyMs = null)
    {
    }
}
