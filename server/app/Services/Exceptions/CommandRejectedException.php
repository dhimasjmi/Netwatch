<?php

declare(strict_types=1);

namespace App\Services\Exceptions;

use RuntimeException;

/** Perintah ditolak oleh aturan bisnis; pesannya aman ditampilkan ke operator. */
final class CommandRejectedException extends RuntimeException
{
}
