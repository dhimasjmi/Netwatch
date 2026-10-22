<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $alert_rule_id
 * @property int $device_id
 * @property \Illuminate\Support\Carbon|null $firing_since
 * @property float|null $last_value
 */
final class AlertState extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['alert_rule_id', 'device_id', 'firing_since', 'last_value', 'updated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['firing_since' => 'datetime', 'last_value' => 'float', 'updated_at' => 'datetime'];
    }

    /** @return BelongsTo<AlertRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
