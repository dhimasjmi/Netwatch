<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceHeartbeat extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'device_id', 'cpu_percent', 'memory_used_mb', 'memory_total_mb',
        'disk_used_gb', 'disk_total_gb', 'uptime_seconds', 'logged_in_user', 'recorded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cpu_percent' => 'float',
            'recorded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
