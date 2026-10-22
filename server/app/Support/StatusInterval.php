<?php

declare(strict_types=1);

namespace App\Support;

/** Satu interval status dalam detik epoch. $end null berarti masih berjalan. */
final class StatusInterval
{
    public function __construct(
        public readonly bool $down,
        public readonly int $start,
        public readonly ?int $end,
    ) {
    }
}
