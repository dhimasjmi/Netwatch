<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BlockRuleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property BlockRuleType $type
 * @property string $value
 * @property string|null $note
 * @property int $policy_id
 * @property bool $is_enabled
 */
class BlockRule extends Model
{
    /** @var list<string> */
    protected $fillable = ['policy_id', 'type', 'value', 'note', 'is_enabled', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => BlockRuleType::class, 'is_enabled' => 'boolean'];
    }

    /** @return BelongsTo<Policy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
