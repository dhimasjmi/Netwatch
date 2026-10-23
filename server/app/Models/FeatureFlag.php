<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property bool $enabled
 * @property int|null $updated_by
 */
final class FeatureFlag extends Model
{
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = 'key';
    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['key', 'enabled', 'updated_by', 'updated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'updated_at' => 'datetime'];
    }
}
