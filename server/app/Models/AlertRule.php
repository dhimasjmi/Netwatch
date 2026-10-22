<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertMetric;
use App\Enums\EventSeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Aturan alert ambang: metrik >= ambang% selama durasi tertentu pada perangkat dalam cakupan.
 *
 * @property int $id
 * @property string $name
 * @property AlertMetric $metric
 * @property int $threshold
 * @property int $duration_minutes
 * @property EventSeverity $severity
 * @property bool $is_enabled
 * @property int|null $device_group_id
 * @property int|null $device_id
 */
final class AlertRule extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'metric', 'threshold', 'duration_minutes', 'severity', 'device_group_id', 'device_id', 'is_enabled', 'created_by'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'metric' => AlertMetric::class,
            'severity' => EventSeverity::class,
            'threshold' => 'integer',
            'duration_minutes' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    /** @return HasMany<AlertState, $this> */
    public function states(): HasMany
    {
        return $this->hasMany(AlertState::class);
    }

    /** @return BelongsTo<DeviceGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class, 'device_group_id');
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function scopeLabel(): string
    {
        return match (true) {
            $this->device_id !== null => 'Perangkat: '.($this->device?->displayLabel() ?? '(dihapus)'),
            $this->device_group_id !== null => 'Grup: '.($this->group?->name ?? '(dihapus)'),
            default => 'Semua perangkat',
        };
    }

    /** Nilai untuk <select> cakupan: "all", "group:ID", atau "device:ID". */
    public function scopeValue(): string
    {
        return match (true) {
            $this->device_id !== null => 'device:'.$this->device_id,
            $this->device_group_id !== null => 'group:'.$this->device_group_id,
            default => 'all',
        };
    }

    public function summary(): string
    {
        return sprintf('%s ≥ %d%% selama %d menit', $this->metric->label(), $this->threshold, $this->duration_minutes);
    }
}
