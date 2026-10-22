<?php

declare(strict_types=1);

namespace App\Enums;

enum CommandStatus: string
{
    case Pending = 'pending';
    case Dispatched = 'dispatched';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Expired = 'expired';

    public function isFinal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Expired], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu',
            self::Dispatched => 'Dikirim',
            self::Succeeded => 'Berhasil',
            self::Failed => 'Gagal',
            self::Expired => 'Kedaluwarsa',
        };
    }
}
