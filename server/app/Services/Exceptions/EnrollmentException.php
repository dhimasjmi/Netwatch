<?php

declare(strict_types=1);

namespace App\Services\Exceptions;

use RuntimeException;

/** Pendaftaran ditolak. Pesannya sengaja generik agar tidak membocorkan alasan ke penyerang. */
final class EnrollmentException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Token pendaftaran tidak valid atau sudah kedaluwarsa.');
    }
}
