<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property list<array{pid: int, name: string, cpu_seconds: float|null, memory_mb: float|null, user: string|null}> $processes
 * @property Carbon $captured_at
 */
class ProcessSnapshot extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['device_id', 'captured_at', 'process_count', 'processes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'processes' => 'array',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
