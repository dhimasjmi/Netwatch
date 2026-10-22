<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kumpulan aturan blokir bernama (mis. "Media sosial") dengan satu cakupan dan satu saklar aktif.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_enabled
 * @property int|null $device_group_id
 * @property int|null $device_id
 */
final class Policy extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'description', 'is_enabled', 'device_group_id', 'device_id', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    /** @return HasMany<BlockRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(BlockRule::class);
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
}
