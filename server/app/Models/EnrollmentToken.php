<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $label
 * @property int $max_uses
 * @property int $used_count
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 */
class EnrollmentToken extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'label', 'token_hash', 'device_group_id', 'max_uses', 'used_count',
        'expires_at', 'revoked_at', 'created_by',
    ];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DeviceGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class, 'device_group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && $this->used_count < $this->max_uses
            && $this->expires_at->isFuture();
    }
}
