<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConnectionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $device_id
 * @property ConnectionStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property string $reason
 */
class DeviceStatusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'device_status_history';

    /** @var list<string> */
    protected $fillable = ['device_id', 'status', 'started_at', 'ended_at', 'reason'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ConnectionStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Interval yang beririsan dengan rentang [from, to].
     *
     * @param  Builder<DeviceStatusHistory>  $query
     * @return Builder<DeviceStatusHistory>
     */
    public function scopeOverlapping(Builder $query, Carbon|\DateTimeInterface $from, Carbon|\DateTimeInterface $to): Builder
    {
        return $query
            ->where('started_at', '<', $to)
            ->where(fn (Builder $q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $from));
    }

    public function reasonLabel(): string
    {
        return match ($this->reason) {
            'heartbeat_timeout' => 'Heartbeat berhenti (komputer mati, jaringan putus, atau agent berhenti)',
            'heartbeat_gap' => 'Tidak ada heartbeat (komputer mati/sleep, jaringan putus, atau agent berhenti); dicatat saat perangkat kembali',
            'heartbeat' => 'Heartbeat diterima kembali',
            'enrolled' => 'Perangkat didaftarkan',
            'backfill' => 'Data awal sebelum pencatatan riwayat',
            'revoked' => 'Akses perangkat dicabut',
            default => $this->reason,
        };
    }
}
