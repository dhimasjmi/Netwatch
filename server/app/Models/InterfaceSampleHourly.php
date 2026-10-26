<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ringkasan bandwidth per interface per jam (hasil rangkuman sampel mentah). */
class InterfaceSampleHourly extends Model
{
    protected $table = 'interface_samples_hourly';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['device_interface_id', 'hour', 'rx_bytes', 'tx_bytes', 'interval_seconds', 'samples'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['hour' => 'datetime'];
    }
}
