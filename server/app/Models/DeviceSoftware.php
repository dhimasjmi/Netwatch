<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $name
 * @property string $version
 * @property string|null $publisher
 */
final class DeviceSoftware extends Model
{
    public $timestamps = false;

    protected $table = 'device_software';

    protected $fillable = ['device_id', 'name', 'version', 'publisher', 'installed_on', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['installed_on' => 'date', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
