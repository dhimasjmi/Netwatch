<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventCategory;
use App\Enums\EventSeverity;
use App\Support\EventCatalog;
use App\Support\WinlogClassifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * @property int $id
 * @property int|null $device_id
 * @property int|null $user_id
 * @property EventCategory $category
 * @property EventSeverity $severity
 * @property string $code
 * @property string $message
 * @property array<string, mixed>|null $details
 * @property Carbon $occurred_at
 */
class Event extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['device_id', 'user_id', 'category', 'severity', 'code', 'message', 'details', 'occurred_at', 'notified_at', 'notification_sent'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => EventCategory::class,
            'severity' => EventSeverity::class,
            'details' => 'array',
            'occurred_at' => 'datetime',
            'notified_at' => 'datetime',
            'notification_sent' => 'boolean',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hint(): ?string
    {
        if (str_starts_with($this->code, 'winlog.')) {
            return WinlogClassifier::hint((string) ($this->details['provider'] ?? ''), (int) ($this->details['event_id'] ?? 0));
        }

        if (isset($this->details['hint']) && is_string($this->details['hint'])) {
            return $this->details['hint']; // saran spesifik dari event itu sendiri (mis. port berisiko)
        }

        try {
            return EventCatalog::definition($this->code)['hint'];
        } catch (InvalidArgumentException) {
            return null; // Kode lama yang sudah dihapus dari katalog tetap bisa ditampilkan.
        }
    }
}
