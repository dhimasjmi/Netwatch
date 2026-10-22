<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TargetKind;
use App\Enums\TargetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $address
 * @property TargetKind $kind
 * @property string $method
 * @property int|null $port
 * @property bool $is_enabled
 * @property TargetStatus $status
 * @property float|null $latency_ms
 * @property int $consecutive_failures
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $status_since
 * @property string|null $notes
 */
final class MonitorTarget extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'address', 'kind', 'method', 'port', 'is_enabled', 'notes', 'created_by'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'kind' => TargetKind::class,
            'status' => TargetStatus::class,
            'port' => 'integer',
            'latency_ms' => 'float',
            'consecutive_failures' => 'integer',
            'is_enabled' => 'boolean',
            'last_checked_at' => 'datetime',
            'status_since' => 'datetime',
        ];
    }

    /** @return HasMany<MonitorTargetCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(MonitorTargetCheck::class);
    }

    /** Cara cek untuk ditampilkan: "Ping" atau "TCP 443". */
    public function methodLabel(): string
    {
        return $this->method === 'tcp' ? 'TCP '.$this->port : 'Ping';
    }
}
