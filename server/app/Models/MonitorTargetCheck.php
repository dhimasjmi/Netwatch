<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $monitor_target_id
 * @property bool $ok
 * @property float|null $latency_ms
 */
final class MonitorTargetCheck extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['monitor_target_id', 'checked_at', 'ok', 'latency_ms'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'ok' => 'boolean', 'latency_ms' => 'float'];
    }
}
