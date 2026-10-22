<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterfaceSample extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['device_interface_id', 'sampled_at', 'rx_bytes', 'tx_bytes', 'interval_seconds'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sampled_at' => 'datetime'];
    }
}
