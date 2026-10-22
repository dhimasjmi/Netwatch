<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $rating
 * @property string|null $comment
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $submitted_at
 */
final class TicketFeedback extends Model
{
    public $timestamps = false;

    protected $table = 'ticket_feedback';

    protected $fillable = ['ticket_id', 'code_hash', 'expires_at', 'sent_via', 'rating', 'comment', 'submitted_at', 'created_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'submitted_at' => 'datetime', 'created_at' => 'datetime', 'rating' => 'integer'];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function isUsable(): bool
    {
        return $this->submitted_at === null && $this->expires_at->isFuture();
    }
}
