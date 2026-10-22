<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $name
 * @property string|null $display_name
 * @property string $state
 * @property string|null $start_mode
 */
final class DeviceService extends Model
{
    public $timestamps = false;

    protected $fillable = ['device_id', 'name', 'display_name', 'state', 'start_mode', 'updated_at'];

    protected function casts(): array
    {
        return ['updated_at' => 'datetime'];
    }
}
