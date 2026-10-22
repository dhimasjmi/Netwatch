<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Aturan bisnis pengelolaan pengguna dilanggar; pesannya aman ditampilkan ke admin. */
final class UserManagementException extends RuntimeException
{
}
