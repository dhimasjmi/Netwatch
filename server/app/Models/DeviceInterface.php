<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $device_id
 * @property string $name
 * @property int|null $speed_bps
 * @property int $last_rx_bytes
 * @property int $last_tx_bytes
 * @property Carbon $last_sample_at
 */
class DeviceInterface extends Model
{
    /** @var list<string> */
    protected $fillable = ['device_id', 'name', 'speed_bps', 'last_rx_bytes', 'last_tx_bytes', 'last_sample_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_sample_at' => 'datetime', 'speed_bps' => 'integer'];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return HasMany<InterfaceSample, $this> */
    public function samples(): HasMany
    {
        return $this->hasMany(InterfaceSample::class);
    }
}
