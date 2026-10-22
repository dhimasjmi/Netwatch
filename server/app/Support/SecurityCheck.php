<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CheckStatus;

final class SecurityCheck
{
    public function __construct(
        public readonly string $title,
        public readonly CheckStatus $status,
        public readonly string $detail,
        public readonly ?string $advice = null,
    ) {
    }
}
