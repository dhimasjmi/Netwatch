<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $protocol
 * @property string $address
 * @property int $port
 * @property string|null $process
 * @property bool $exposed
 * @property \Illuminate\Support\Carbon $first_seen_at
 * @property \Illuminate\Support\Carbon $last_seen_at
 */
final class DevicePort extends Model
{
    public $timestamps = false;

    protected $fillable = ['device_id', 'protocol', 'address', 'port', 'process', 'exposed', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['exposed' => 'boolean', 'port' => 'integer', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }
}
