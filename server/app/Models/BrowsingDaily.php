<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ringkasan kunjungan per perangkat, hari, jenis, dan situs (hasil rollup data mentah yang sudah melewati masa retensinya).
 *
 * @property string $host kosong bila koneksi tanpa nama domain
 * @property string $ip   terisi hanya untuk koneksi tanpa nama domain
 * @property int $visits
 */
final class BrowsingDaily extends Model
{
    public $timestamps = false;

    protected $table = 'browsing_daily';

    protected $fillable = ['device_id', 'day', 'kind', 'host', 'ip', 'visits', 'first_at', 'last_at'];

    protected function casts(): array
    {
        return ['day' => 'date', 'first_at' => 'datetime', 'last_at' => 'datetime'];
    }
}
