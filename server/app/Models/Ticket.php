<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int|null $device_id
 * @property string $requester_name
 * @property string|null $requester_email
 * @property string $subject
 * @property string $description
 * @property TicketCategory $category
 * @property TicketPriority $priority
 * @property TicketStatus $status
 * @property string $source
 * @property int|null $assigned_to
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 */
final class Ticket extends Model
{
    protected $fillable = [
        'device_id', 'requester_name', 'requester_email', 'subject', 'description', 'category', 'priority', 'status',
        'source', 'assigned_to', 'created_by', 'ip_address', 'resolved_at', 'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'category' => TicketCategory::class,
            'priority' => TicketPriority::class,
            'status' => TicketStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function number(): string
    {
        return 'T-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<TicketComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasOne<TicketFeedback, $this> */
    public function feedback(): HasOne
    {
        return $this->hasOne(TicketFeedback::class);
    }
}
