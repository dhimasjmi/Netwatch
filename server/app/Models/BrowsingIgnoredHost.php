<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $domain
 * @property string|null $note
 */
final class BrowsingIgnoredHost extends Model
{
    public $timestamps = false;

    protected $fillable = ['domain', 'note', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
