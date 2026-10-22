<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $device_id
 * @property \Illuminate\Support\Carbon $visited_at
 * @property string $kind
 * @property string|null $host
 * @property string|null $ip
 * @property int|null $port
 * @property string|null $url
 * @property string|null $process
 * @property string|null $browser
 */
final class BrowsingVisit extends Model
{
    public $timestamps = false;

    protected $fillable = ['device_id', 'visited_at', 'kind', 'host', 'ip', 'port', 'url', 'process', 'browser', 'dedupe_hash'];

    protected function casts(): array
    {
        return ['visited_at' => 'datetime', 'port' => 'integer'];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
