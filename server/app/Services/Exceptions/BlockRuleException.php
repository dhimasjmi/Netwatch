<?php

declare(strict_types=1);

namespace App\Services\Exceptions;

use RuntimeException;

/** Aturan blokir ditolak; pesannya aman ditampilkan ke admin. */
final class BlockRuleException extends RuntimeException
{
}
