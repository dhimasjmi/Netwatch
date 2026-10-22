<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string|null $manufacturer
 * @property string|null $model
 * @property string|null $serial
 * @property string|null $cpu
 * @property int|null $cpu_cores
 * @property int|null $cpu_threads
 * @property int|null $memory_mb
 * @property string|null $os
 * @property string|null $os_version
 * @property string|null $os_build
 * @property list<array{model: string, size_gb: int}>|null $disks
 */
final class DeviceHardware extends Model
{
    public $timestamps = false;

    protected $table = 'device_hardware';

    protected $fillable = ['device_id', 'manufacturer', 'model', 'serial', 'cpu', 'cpu_cores', 'cpu_threads', 'memory_mb', 'os', 'os_version', 'os_build', 'disks', 'updated_at'];

    protected function casts(): array
    {
        return ['disks' => 'array', 'updated_at' => 'datetime'];
    }

    public function device(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function memoryLabel(): string
    {
        return $this->memory_mb === null ? '—' : round($this->memory_mb / 1024, 1).' GB';
    }

    public function disksLabel(): string
    {
        $disks = $this->disks ?? [];

        return $disks === [] ? '—' : implode(', ', array_map(static fn (array $d): string => $d['size_gb'].' GB', $disks));
    }
}
