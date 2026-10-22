<?php

declare(strict_types=1);

namespace NetWatch\Agent\Http;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode = 0)
    {
        parent::__construct($message);
    }

    public function isAuthFailure(): bool
    {
        return $this->statusCode === 401 || $this->statusCode === 403;
    }
}
