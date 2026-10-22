<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OsFamily;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $hostname
 * @property string|null $display_name
 * @property OsFamily $os_family
 * @property array<string, mixed>|null $latest_metrics
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $maintenance_until
 * @property string|null $maintenance_note
 * @property bool|null $ping_ok
 * @property float|null $ping_ms
 * @property Carbon|null $ping_checked_at
 */
class Device extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'device_group_id', 'enrollment_token_id', 'hostname', 'display_name', 'os_family',
        'os_version', 'agent_version', 'ip_address', 'mac_address', 'token_hash',
        'latest_metrics', 'last_seen_at', 'enrolled_at', 'revoked_at',
        'policy_hash', 'policy_error', 'policy_reported_at',
        'maintenance_until', 'maintenance_note', 'ping_ok', 'ping_ms', 'ping_checked_at',
    ];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'os_family' => OsFamily::class,
            'latest_metrics' => 'array',
            'last_seen_at' => 'datetime',
            'policy_reported_at' => 'datetime',
            'maintenance_until' => 'datetime',
            'ping_checked_at' => 'datetime',
            'inventory_reported_at' => 'datetime',
            'assets_reported_at' => 'datetime',
            'ping_ok' => 'boolean',
            'ping_ms' => 'float',
            'enrolled_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DeviceGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class, 'device_group_id');
    }

    /** @return HasMany<DeviceHeartbeat, $this> */
    public function heartbeats(): HasMany
    {
        return $this->hasMany(DeviceHeartbeat::class);
    }

    /** @return HasMany<DeviceStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(DeviceStatusHistory::class);
    }

    /** @return HasMany<DeviceCommand, $this> */
    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }

    /** @return HasMany<ProcessSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(ProcessSnapshot::class);
    }

    /** @return HasOne<ProcessSnapshot, $this> */
    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(ProcessSnapshot::class)->latestOfMany('captured_at');
    }

    /**
     * @param  Builder<Device>  $query
     * @return Builder<Device>
     */
    public function hardware(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DeviceHardware::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isOnline(): bool
    {
        return $this->revoked_at === null
            && $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds((int) config('netwatch.offline_after')));
    }

    /** Selama maintenance, notifikasi offline tidak dikirim (event dan riwayat uptime tetap dicatat). */
    public function isInMaintenance(): bool
    {
        return $this->maintenance_until !== null && $this->maintenance_until->isFuture();
    }

    public function displayLabel(): string
    {
        return $this->display_name ?: $this->hostname;
    }
}
