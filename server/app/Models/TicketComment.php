<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $body
 * @property bool $is_internal
 * @property \Illuminate\Support\Carbon $created_at
 */
final class TicketComment extends Model
{
    public $timestamps = false;

    protected $fillable = ['ticket_id', 'user_id', 'body', 'is_internal', 'created_at'];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
